/**
 * Data-driven definitions for the item-master lookups consolidated onto the
 * Lookups admin page. This file is plain configuration — no components, no
 * rendering logic. `LookupPanel` (built separately) reads one entry from
 * LOOKUP_CONFIGS and renders the add/edit form + list table generically.
 *
 * Field `type` values understood by LookupPanel:
 *   - 'text'      — single-line text input
 *   - 'textarea'  — multi-line text input
 *   - 'number'    — numeric input
 *   - 'checkbox'  — boolean toggle
 *   - 'select'    — fixed-option dropdown (uses `options`)
 *   - 'category'  — category picker (loads categories, stores category_id)
 *   - 'unit'      — unit-of-measure picker (loads units, stores unit_id)
 *
 * Column `key` values are usually a raw field on the row, except two derived
 * keys the panel resolves itself:
 *   - 'category_name' — looked up from the row's category_id
 *   - 'unit_name'      — looked up from the row's nested unit object
 *
 * Column `format` values (optional; how the raw/derived value is displayed):
 *   - 'bool'     — render truthy/falsy as Yes/No (React renders true/false as
 *                  nothing, so any boolean column MUST set this)
 *   - 'currency' — render as peso-formatted money
 *   - 'decimal'  — render a decimal(10,6)-sourced numeric string (e.g. "1.000000")
 *                  trimmed for display
 *
 * Field-level flags:
 *   - `omitWhenBlank: true` — the panel must NOT submit this key at all when
 *     the input is blank, rather than sending '' (which Laravel's
 *     ConvertEmptyStringsToNull turns into a present `null`, failing
 *     `sometimes|integer` validation). Used on the three `sort_order` fields.
 *     Do not "fix" a blank sort_order by defaulting it to 0 — that silently
 *     reorders rows; the column must stay unset to keep the DB default.
 *   - `allowEmpty: true` — on `category` fields, tells the panel to offer a
 *     blank "All categories" / "Uncategorized" option, matching the backend's
 *     `nullable|integer|exists:...` rule for capacity/variant category_id.
 *
 * category_id filtering gotcha: when calling a categoryScoped lookup's
 * `getAll`, only pass `category_id` when a category is actually selected.
 * `URLSearchParams` stringifies `undefined` as the literal string
 * "undefined", so `getAll({ category_id: undefined })` sends
 * `?category_id=undefined` to the API instead of omitting the param.
 *
 * Server-side search: `unitsAPI.getAll` accepts `?search=` (filters on
 * unit_name) and it is the only one of the six lookups with a server-side
 * filter. This config does not expose it — the panel filters all six lookups
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
} from '../services/api';

export const LOOKUP_CONFIGS = {
  categories: {
    label: 'Categories',
    singular: 'Category',
    api: categoriesAPI,
    idField: 'category_id',
    nameField: 'category_name',
    categoryScoped: false,
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
        default: 'product',
        options: [
          { value: 'product', label: 'Product' },
          { value: 'part', label: 'Part' },
          { value: 'accessory', label: 'Accessory' },
          { value: 'service', label: 'Service' },
        ],
      },
      {
        name: 'description',
        label: 'Description (optional)',
        type: 'textarea',
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
    categoryScoped: false,
    supportsMerge: false,
    fields: [
      {
        name: 'brand_name',
        label: 'Brand name',
        type: 'text',
        required: true,
        maxLength: 100,
      },
      {
        name: 'description',
        label: 'Description (optional)',
        type: 'textarea',
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
    categoryScoped: false,
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
    categoryScoped: true,
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
      {
        name: 'category_id',
        label: 'Category',
        type: 'category',
        allowEmpty: true,
        hint: 'Scopes this capacity to a category.',
      },
      {
        name: 'description',
        label: 'Description (optional)',
        type: 'textarea',
      },
      {
        name: 'sort_order',
        label: 'Sort order',
        type: 'number',
        min: 0,
        max: 255,
        omitWhenBlank: true,
        hint: 'Leave blank to use the default order. Integer 0-255.',
      },
    ],
    columns: [
      { key: 'capacity_value', label: 'Value' },
      { key: 'category_name', label: 'Category' },
      { key: 'sort_order', label: 'Sort order' },
    ],
  },

  variants: {
    label: 'Variants',
    singular: 'Variant',
    api: variantsAPI,
    idField: 'variant_id',
    nameField: 'variant_name',
    categoryScoped: true,
    supportsMerge: true,
    fields: [
      {
        name: 'variant_name',
        label: 'Variant name',
        type: 'text',
        required: true,
        maxLength: 100,
      },
      {
        name: 'category_id',
        label: 'Category',
        type: 'category',
        allowEmpty: true,
        hint: 'Scopes this variant to a category.',
      },
      {
        name: 'description',
        label: 'Description (optional)',
        type: 'textarea',
      },
      {
        name: 'sort_order',
        label: 'Sort order',
        type: 'number',
        min: 0,
        max: 255,
        omitWhenBlank: true,
        hint: 'Leave blank to use the default order. Integer 0-255.',
      },
    ],
    columns: [
      { key: 'variant_name', label: 'Name' },
      { key: 'category_name', label: 'Category' },
      { key: 'sort_order', label: 'Sort order' },
    ],
  },
};

export const LOOKUP_TABS = [
  {
    key: 'appliance',
    label: 'Appliance Lookup',
    lookups: ['categories', 'brands', 'variants', 'capacities'],
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
