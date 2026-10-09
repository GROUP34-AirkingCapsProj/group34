import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import AnalyticsRecords, { analyticsCsv } from './AnalyticsRecords';

test('paginates the full population and exports all filtered rows with context', () => {
  const columns = [{ key: 'name', label: 'Product' }, { key: 'value', label: 'Value' }];
  const rows = Array.from({ length: 12 }, (_, id) => ({ id, name: `Stock-${id}`, value: id + 0.25 }));
  render(<AnalyticsRecords title="Stock records" columns={columns} rows={rows} context={{ Location: 'Warehouse A' }} />);
  expect(screen.getByText('Stock-9')).toBeInTheDocument();
  expect(screen.queryByText('Stock-10')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: /^Next →$/ }));
  expect(screen.getByText('Stock-11')).toBeInTheDocument();
  expect(screen.queryByText('Stock-0')).not.toBeInTheDocument();
  const csv = analyticsCsv(columns, rows, { Location: 'Warehouse A' });
  expect(csv).toContain('"Location","Warehouse A"');
  expect(csv).toContain('"Stock-0","0.25"');
  expect(csv).toContain('"Stock-11","11.25"');
});
