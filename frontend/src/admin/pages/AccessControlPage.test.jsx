import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import AccessControlPage from './AccessControlPage';

const mockNavigate = jest.fn();
jest.mock('react-router-dom', () => ({
  ...jest.requireActual('react-router-dom'),
  useNavigate: () => mockNavigate,
}));

// Keep the parent isolated from the panels' API calls / layout chrome.
jest.mock('../components/AdminLayout', () => ({ children }) => <div>{children}</div>);
jest.mock('./RolePermissions', () => ({ tabSwitcher }) => <div>ROLE PANEL{tabSwitcher}</div>);
jest.mock('./UserPermissionOverrides', () => ({ tabSwitcher }) => <div>OVERRIDES PANEL{tabSwitcher}</div>);

const renderAt = (path) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <AccessControlPage />
    </MemoryRouter>
  );

beforeEach(() => mockNavigate.mockClear());

test('renders Role Permissions panel and active tab at /roles', () => {
  renderAt('/roles');
  expect(screen.getByText('ROLE PANEL')).toBeInTheDocument();
  expect(screen.queryByText('OVERRIDES PANEL')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Role Permissions' }).className).toMatch(/is-active/);
});

test('renders User Overrides panel and active tab at /user-permissions', () => {
  renderAt('/user-permissions');
  expect(screen.getByText('OVERRIDES PANEL')).toBeInTheDocument();
  expect(screen.queryByText('ROLE PANEL')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'User Overrides' }).className).toMatch(/is-active/);
});

test('clicking the inactive tab navigates to its path', () => {
  renderAt('/roles');
  fireEvent.click(screen.getByRole('button', { name: 'User Overrides' }));
  expect(mockNavigate).toHaveBeenCalledWith('/user-permissions');
});

test('clicking the already-active tab does not navigate', () => {
  renderAt('/roles');
  fireEvent.click(screen.getByRole('button', { name: 'Role Permissions' }));
  expect(mockNavigate).not.toHaveBeenCalled();
});
