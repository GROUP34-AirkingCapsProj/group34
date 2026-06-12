import { buildSidebar } from './sidebarBuilder';

const sections = [
  { label: 'PROCUREMENT & PLANNING', items: [
    { name: 'Item Master', path: '/admin/items', icon: 'items' },
    { name: 'System Configuration', path: '/admin/config', icon: 'config' },
  ] },
];

test('shows bundle item when required perm granted', () => {
  const out = buildSidebar(sections, { 'item_master_management.read': true }, false);
  const labels = out.flatMap((s) => s.items.map((i) => i.name));
  expect(labels).toContain('Item Master');
  expect(labels).not.toContain('System Configuration'); // admin-only, non-admin hidden
});

test('admin sees admin-only items', () => {
  const out = buildSidebar(sections, {}, true);
  const labels = out.flatMap((s) => s.items.map((i) => i.name));
  expect(labels).toContain('System Configuration');
});

test('empty sections are dropped', () => {
  const out = buildSidebar(sections, {}, false);
  expect(out.length).toBe(0);
});

test('role-open items (warranty) are shown to non-admins', () => {
  const s = [{ label: 'COMPLIANCE & SUPPORT', items: [
    { name: 'Warranty Records', path: '/admin/warranties', icon: 'warranty' },
    { name: 'System Configuration', path: '/admin/config', icon: 'config' },
  ] }];
  const out = buildSidebar(s, {}, false);
  const labels = out.flatMap((x) => x.items.map((i) => i.name));
  expect(labels).toContain('Warranty Records');       // role-open: visible
  expect(labels).not.toContain('System Configuration'); // admin-only: hidden
});
