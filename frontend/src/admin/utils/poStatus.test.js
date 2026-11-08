import { getStatusConfig } from './poStatus';

test.each([
  ['Draft', 'DRAFT', 'draft'],
  ['Approved/Open', 'APPROVED', 'approved'],
  ['Partially Received', 'PARTIALLY RECEIVED', 'partial'],
  ['Fully Received', 'FULLY RECEIVED', 'completed'],
  ['Short-Closed', 'SHORT-CLOSED', 'completed'],
  ['Cancelled', 'CANCELLED', 'cancelled'],
])('renders lifecycle %s', (status, label, color) => {
  expect(getStatusConfig(status)).toEqual({ label, color });
});
