<?php

namespace App\Reports;

use App\Models\CategoryLookup;
use App\Models\Location;
use App\Models\ReportRun;
use App\Models\User;
use App\Reports\Renderers\CsvReportRenderer;
use App\Reports\Renderers\PdfReportRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The one path every report goes through, from a raw request to a stored
 * file plus its audit row.
 *
 * The whole point of the registry/ReportParameter design is that a report
 * cannot be offered a filter it does not implement, nor quietly ignore one
 * that was sent — so this class validates submitted parameters against the
 * definition's *declared* parameters() before ever calling build(), and
 * rejects anything outside that set rather than silently dropping it.
 *
 * An empty dataset or a PDF beyond the definition's row cap aborts before
 * anything is written — no orphaned file, no orphaned report_runs row. If
 * the row insert fails after the file was written, the file is removed too;
 * neither side is ever left without the other.
 */
class ReportRunner
{
    /** How long a generated file is kept before pruning (Task 13 reuses this). */
    public const RETENTION_DAYS = 90;

    private const DISK = 'local';

    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly PdfReportRenderer $pdfRenderer,
        private readonly CsvReportRenderer $csvRenderer,
    ) {
    }

    /**
     * @param array<string,mixed> $parameters raw request input, unvalidated
     *
     * @throws \InvalidArgumentException          unknown report key or undeclared format
     * @throws AuthorizationException              user may not run this report
     * @throws ValidationException                 parameters fail the definition's own rules,
     *                                              or include a key the definition does not declare
     * @throws EmptyReportException                build() produced no rows
     * @throws RowCapExceededException              PDF row count exceeds the definition's cap
     */
    public function run(string $reportKey, string $format, array $parameters, User $user): ReportRun
    {
        $definition = $this->registry->get($reportKey);
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown report: {$reportKey}");
        }

        if (! $definition->availableTo($user)) {
            throw new AuthorizationException("You may not run the {$definition->title()} report.");
        }

        if (! in_array($format, $definition->formats(), true)) {
            throw new \InvalidArgumentException(
                "The {$definition->title()} report does not support the \"{$format}\" format."
            );
        }

        $declaredParameters = $definition->parameters();
        $applied = $this->validateAndApplyDefaults($declaredParameters, $parameters, $definition->title());
        $reportParams = new ReportParameters($applied);

        $dataset = $definition->build($reportParams, $user);

        if ($dataset->isEmpty()) {
            throw new EmptyReportException(
                "The {$definition->title()} report returned no rows for the selected filters."
            );
        }

        if ($format === 'pdf' && $dataset->rowCount() > $definition->pdfRowCap()) {
            throw new RowCapExceededException(
                "The {$definition->title()} report has {$dataset->rowCount()} rows, above the PDF limit of "
                . "{$definition->pdfRowCap()}. Choose CSV instead, or narrow your filters."
            );
        }

        $meta = $this->buildMeta($declaredParameters, $reportParams);
        $renderer = $format === 'pdf' ? $this->pdfRenderer : $this->csvRenderer;
        $content = $renderer->render($dataset, $definition->title(), $meta);

        $extension = $format === 'pdf' ? 'pdf' : 'csv';
        $fileName = $this->fileName($definition->title(), $extension);
        $path = $this->storedPath($fileName, $extension);

        Storage::disk(self::DISK)->put($path, $content);
        $sizeBytes = Storage::disk(self::DISK)->size($path);

        try {
            return ReportRun::create([
                'report_key'      => $definition->key(),
                'title_snapshot'  => $definition->title(),
                'format'          => $format,
                'parameters'      => $applied,
                'file_path'       => $path,
                'file_name'       => $fileName,
                'file_size_bytes' => $sizeBytes,
                'row_count'       => $dataset->rowCount(),
                'generated_by'    => $user->user_id,
                'expires_at'      => now()->addDays(self::RETENTION_DAYS),
            ]);
        } catch (\Throwable $e) {
            // No orphaned file if the row could not be written.
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }
    }

    /**
     * Validate submitted parameters against the union of what the definition
     * declares, rejecting any key outside that set, then fold in defaults
     * for anything the caller omitted.
     *
     * @param ReportParameter[]    $declaredParameters
     * @param array<string,mixed>  $parameters
     * @return array<string,mixed>
     */
    private function validateAndApplyDefaults(array $declaredParameters, array $parameters, string $title): array
    {
        $allowedKeys = [];
        $rules = [];
        foreach ($declaredParameters as $parameter) {
            $allowedKeys = array_merge($allowedKeys, $parameter->requestKeys());
            $rules = array_merge($rules, $parameter->rules());
        }
        $allowedKeys = array_unique($allowedKeys);

        $unknown = array_diff(array_keys($parameters), $allowedKeys);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                $unknown,
                ["This filter is not supported by the {$title} report."]
            ));
        }

        Validator::make($parameters, $rules)->validate();

        $applied = $parameters;
        foreach ($declaredParameters as $parameter) {
            if ($parameter->type === ReportParameter::TYPE_DATE_RANGE || $parameter->default === null) {
                continue;
            }

            $submitted = $applied[$parameter->key] ?? null;
            if ($submitted === null || $submitted === '') {
                $applied[$parameter->key] = $parameter->default;
            }
        }

        return $applied;
    }

    /**
     * Summarise the filters actually applied, one entry per declared
     * parameter that has a value — never per raw request key, so an
     * id-shaped filter reads as a name in the footer/header rather than a
     * bare number. "None — all records" when nothing was set at all.
     *
     * @param ReportParameter[] $declaredParameters
     * @return array<string,string>
     */
    private function buildMeta(array $declaredParameters, ReportParameters $params): array
    {
        $meta = [];

        foreach ($declaredParameters as $parameter) {
            $value = $this->metaValueFor($parameter, $params);
            if ($value !== null) {
                $meta[$parameter->label] = $value;
            }
        }

        return $meta === [] ? ['Filters' => 'None — all records'] : $meta;
    }

    private function metaValueFor(ReportParameter $parameter, ReportParameters $params): ?string
    {
        return match ($parameter->type) {
            ReportParameter::TYPE_DATE_RANGE => $this->dateRangeMeta($params),
            ReportParameter::TYPE_LOCATION => $params->has($parameter->key)
                ? (Location::find($params->int($parameter->key))?->location_name ?? "#{$params->int($parameter->key)}")
                : null,
            ReportParameter::TYPE_CATEGORY => $params->has($parameter->key)
                ? (CategoryLookup::find($params->int($parameter->key))?->category_name ?? "#{$params->int($parameter->key)}")
                : null,
            ReportParameter::TYPE_USER => $params->has($parameter->key)
                ? $this->userLabel($params->int($parameter->key))
                : null,
            ReportParameter::TYPE_LOCATION_TYPE, ReportParameter::TYPE_SELECT => $params->has($parameter->key)
                ? ($parameter->options[$params->get($parameter->key)] ?? (string) $params->get($parameter->key))
                : null,
            default => null,
        };
    }

    private function dateRangeMeta(ReportParameters $params): ?string
    {
        $from = $params->get('date_from');
        $to = $params->get('date_to');

        if ($from === null && $to === null) {
            return null;
        }

        return ($from ?? '…') . ' to ' . ($to ?? '…');
    }

    private function userLabel(?int $userId): string
    {
        if ($userId === null) {
            return '—';
        }

        $user = User::find($userId);
        if ($user === null) {
            return "#{$userId}";
        }

        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $name !== '' ? $name : ($user->username ?? "#{$userId}");
    }

    /**
     * The name the file is DOWNLOADED as: {Title_In_Snake}_{Y-m-d_His}.{ext},
     * e.g. Inventory_Summary_Report_2026-08-17_143022.pdf. Human-facing, so it
     * carries nothing but the title and the moment.
     */
    private function fileName(string $title, string $extension): string
    {
        $slug = str_replace(' ', '_', $title);
        $timestamp = now()->format('Y-m-d_His');

        return "{$slug}_{$timestamp}.{$extension}";
    }

    /**
     * Where the file is STORED, which is deliberately not the download name.
     *
     * The name has second granularity, so two runs of the same report and
     * format inside one second collide. Sharing a path meant Storage::put
     * overwrote, both rows pointed at one file, and the first run downloaded
     * the second's data under its own filters and row count — then pruning
     * either one deleted the file the other still claimed to have.
     *
     * The random suffix lives only in the path: the user still gets the clean
     * name back on download, because Storage::download() is passed file_name
     * separately.
     */
    private function storedPath(string $fileName, string $extension): string
    {
        $stem = substr($fileName, 0, -(strlen($extension) + 1));

        return "reports/{$stem}_" . Str::random(8) . ".{$extension}";
    }
}
