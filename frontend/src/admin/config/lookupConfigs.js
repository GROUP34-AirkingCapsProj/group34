/**
 * Data-driven definitions for the item-master lookups consolidated onto the
 * Lookups admin page. This file is plain configuration — no components, no
 * rendering logic. `LookupPanel` (built separately) reads one entry from
 * LOOKUP_CONFIGS and renders the add/edit form + list table generically.
 *
 * Field `type` values understood by LookupPanel:
 *   - 'text'      — single-line text input
 *   - 'textarea'  — multi-line text input
 *                  (supported but not currently used by any config below)
 *   - 'number'    — numeric input
 *                  (supported but not currently used by any config below)
 *   - 'checkbox'  — boolean toggle (used by acTypes.is_two_piece; honours
 *                  `default`, and always submits a real boolean)
 *   - 'select'    — fixed-option dropdown (uses `options`)
 *   - 'unit'      — unit-of-measure picker (loads units, stores unit_id)
 *                  (supported but not currently used by any config below)
 *
 * Column `key` values are raw fields on the row.
 *
 * Column `format` values (optional; how the raw/derived value is displayed):
 *   - 'bool'     — render truthy/falsy as Yes/No (React renders true/false as
 *                  nothing, so any boolean column MUST set this)
 *                  (used by acTypes.is_two_piece)
 *   - 'currency' — render as peso-formatted money
 *   - 'decimal'  — render a decimal(10,6)-sourced numeric string (e.g. "1.000000")
 *                  trimmed for display
 *                  (supported but not currently used by any config below)
 *
 * Field-level flags:
 *   - `omitWhenBlank: true` — the panel must NOT submit this key at all when
 *     the input is blank, rather than sending '' (which Laravel's
 *     ConvertEmptyStringsToNull turns into a present `null`, failing
 *     `sometimes|integer` validation).
 *     (supported but not currently used by any config below)
 *
 * Server-side search: `unitsAPI.getAll` accepts `?search=` (filters on
 * unit_name) and it is the only one of the lookups with a server-side
 * filter. This config does not expose it — the panel filters every lookup
 * client-side uniformly, so the units `?search=` param is left unused by
 * design, not by oversight.
 *
 * `usage_count` is rendered by LookupPanel itself, as a mandatory trailing
 * "Used by" column on every lookup. Do NOT list `usage_count` in any entry's
 * `columns` array below — the panel appends it unconditionally, so doing so
 * would render it twice.
 */
import {
  categoriesAPI,
  brandsAPI,
  unitsAPI,
  capacitiesAPI,
  variantsAPI,
  acTypesAPI,
} from '../services/api';

export const LOOKUP_CONFIGS = {
  categories: {
    label: 'Categories',
    singular: 'Category',
    api: categoriesAPI,
    idField: 'category_id',
    nameField: 'category_name',
    supportsMerge: false,
    fields: [
      {
        name: 'category_name',
        label: 'Category name',
        type: 'text',
        required: true,
        maxLength: 100,
      },
      {
        name: 'category_type',
        label: 'Type',
        type: 'select',
        required: true,
        default: 'appliance',
        options: [
          { value: 'appliance', label: 'Appliance' },
          { value: 'installation_material', label: 'Installation Material' },
        ],
      },
    ],
    columns: [
      { key: 'category_name', label: 'Name' },
      { key: 'category_type', label: 'Type' },
      { key: 'items_count', label: 'Items' },
      { key: 'total_value', label: 'Total value', format: 'currency' },
    ],
  },

  brands: {
    label: 'Brands',
    singular: 'Brand',
    api: brandsAPI,
    idField: 'brand_id',
    nameField: 'brand_name',
    supportsMerge: false,
    fields: [
      {
        name: 'brand_name',
        label: 'Brand name',
        type: 'text',
        required: true,
        maxLength: 100,
      },
    ],
    columns: [
      { key: 'brand_name', label: 'Name' },
      { key: 'items_count', label: 'Items' },
      { key: 'total_value', label: 'Total value', format: 'currency' },
    ],
  },

  units: {
    label: 'Units of Measure',
    singular: 'Unit',
    api: unitsAPI,
    idField: 'unit_id',
    nameField: 'unit_name',
    supportsMerge: false,
    fields: [
      {
        name: 'unit_name',
        label: 'Unit name',
        type: 'text',
        required: true,
        maxLength: 50,
        placeholder: 'e.g. Roll',
      },
      {
        name: 'unit_abbreviation',
        label: 'Abbreviation',
        type: 'text',
        maxLength: 10,
        placeholder: 'e.g. rl',
      },
    ],
    columns: [
      { key: 'unit_name', label: 'Name' },
      { key: 'unit_abbreviation', label: 'Abbreviation' },
    ],
  },

  capacities: {
    label: 'Capacities',
    singular: 'Capacity',
    api: capacitiesAPI,
    idField: 'capacity_id',
    nameField: 'capacity_value',
    supportsMerge: true,
    fields: [
      {
        name: 'capacity_value',
        label: 'Capacity value',
        type: 'text',
        required: true,
        maxLength: 20,
        placeholder: '1.5HP',
      },
    ],
    columns: [
      { key: 'capacity_value', label: 'Value' },
    ],
  },

  variants: {
    label: 'Variants',
    singular: 'Variant',
    api: variantsAPI,
    idField: 'variant_id',
    nameField: 'variant_name',
    supportsMerge: true,
    fields: [
      {
        name: 'variant_name',
        label: 'Variant name',
        type: 'text',
        required: true,
        maxLength: 100,
      },
    ],
    columns: [
      { key: 'variant_name', label: 'Name' },
    ],
  },

  acTypes: {
    label: 'AC Types',
    singular: 'AC Type',
    api: acTypesAPI,
    idField: 'ac_type_id',
    nameField: 'ac_type_name',
    supportsMerge: false,
    fields: [
      {
        name: 'ac_type_name',
        label: 'AC type name',
        type: 'text',
        required: true,
        maxLength: 60,
      },
      {
        // First use of LookupPanel's 'checkbox' field type. This is the
        // DEFAULT piece count for the kind, not a rule -- a product under a
        // two-piece type may still be registered as a complete unit.
        name: 'is_two_piece',
        label: 'Two-piece (indoor + outdoor)',
        type: 'checkbox',
        default: true,
      },
    ],
    columns: [
      { key: 'ac_type_name', label: 'Name' },
      { key: 'is_two_piece', label: 'Two-piece', format: 'bool' },
    ],
  },
};

export const LOOKUP_TABS = [
  {
    key: 'appliance',
    label: 'Appliance Lookup',
    lookups: ['categories', 'brands', 'variants', 'capacities', 'acTypes'],
  },
  {
    key: 'installation',
    label: 'Installation Materials Lookup',
    lookups: ['units'],
  },
];

/** Returns the tab that contains the given lookup key, falling back to the first tab. */
export const tabForLookup = (lookupKey) =>
  LOOKUP_TABS.find((tab) => tab.lookups.includes(lookupKey)) || LOOKUP_TABS[0];
