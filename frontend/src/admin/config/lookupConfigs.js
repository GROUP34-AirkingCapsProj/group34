/**
 * Data-driven definitions for the six item-master lookups consolidated onto the
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
 */
import {
  categoriesAPI,
  brandsAPI,
  unitsAPI,
  capacitiesAPI,
  variantsAPI,
  issuableUnitPresetsAPI,
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
      { key: 'total_value', label: 'Total value' },
      { key: 'usage_count', label: 'Usage' },
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
      { key: 'total_value', label: 'Total value' },
      { key: 'usage_count', label: 'Usage' },
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
      { key: 'usage_count', label: 'Usage' },
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
        hint: 'Leave blank to use the default order. Integer 0-255.',
      },
    ],
    columns: [
      { key: 'capacity_value', label: 'Value' },
      { key: 'category_name', label: 'Category' },
      { key: 'sort_order', label: 'Sort order' },
      { key: 'usage_count', label: 'Usage' },
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
        hint: 'Leave blank to use the default order. Integer 0-255.',
      },
    ],
    columns: [
      { key: 'variant_name', label: 'Name' },
      { key: 'category_name', label: 'Category' },
      { key: 'sort_order', label: 'Sort order' },
      { key: 'usage_count', label: 'Usage' },
    ],
  },

  presets: {
    label: 'Issuable Unit Presets',
    singular: 'Preset',
    api: issuableUnitPresetsAPI,
    idField: 'preset_id',
    nameField: 'label',
    categoryScoped: false,
    supportsMerge: false,
    fields: [
      {
        name: 'label',
        label: 'Label',
        type: 'text',
        required: true,
        maxLength: 100,
        placeholder: 'Roll',
      },
      {
        name: 'unit_id',
        label: 'Unit',
        type: 'unit',
        required: true,
      },
      {
        name: 'default_conversion_factor',
        label: 'Default conversion factor',
        type: 'number',
        required: true,
        default: 1,
        min: 0,
        max: 10000,
        step: 'any',
        hint: 'Number of inventory pieces deducted per one of this issuable unit. Must be greater than 0 and less than 10000, up to 6 decimal places.',
      },
      {
        name: 'is_whole_package',
        label: 'Represents one full package',
        type: 'checkbox',
        hint: 'Check if this preset represents one full package.',
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
        hint: 'Leave blank to use the default order. Integer 0-255.',
      },
    ],
    columns: [
      { key: 'label', label: 'Label' },
      { key: 'unit_name', label: 'Unit' },
      { key: 'default_conversion_factor', label: 'Conversion factor' },
      { key: 'is_whole_package', label: 'Full package' },
      { key: 'usage_count', label: 'Usage' },
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
    lookups: ['units', 'presets'],
  },
];

/** Returns the tab that contains the given lookup key, falling back to the first tab. */
export const tabForLookup = (lookupKey) =>
  LOOKUP_TABS.find((tab) => tab.lookups.includes(lookupKey)) || LOOKUP_TABS[0];
