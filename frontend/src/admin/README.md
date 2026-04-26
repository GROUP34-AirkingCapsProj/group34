# Admin Dashboard - AirKing Inventory Management System

This folder contains all the React components for the AirKing Admin Dashboard.

## Structure

```
admin/
├── components/          # Shared reusable components
│   ├── Sidebar.js       # Navigation sidebar
│   ├── Header.js        # Top header with search
│   ├── Modal.js         # Reusable modal component
│   └── AdminLayout.js  # Layout wrapper for admin pages
├── pages/               # Page components
│   ├── Landing.js       # Landing/home page
│   ├── Login.js         # Login page
│   ├── Dashboard.js     # Admin dashboard
│   ├── BranchManagement.js
│   ├── InventoryManagement.js
│   ├── ItemManagement.js
│   ├── TransactionManagement.js
│   ├── UserManagement.js
│   └── WarehouseManagement.js
├── styles/              # CSS stylesheets
│   ├── dashboard_air.css
│   ├── landing_air.css
│   ├── style.css
│   └── [other CSS files]
└── index.js             # Export file for easier imports
```

## Routes

All routes are configured in `src/App.js`:

- `/admin` - Landing page
- `/admin/login` - Login page
- `/admin/dashboard` - Dashboard
- `/admin/branches` - Branch Management
- `/admin/inventory` - Inventory Management
- `/admin/items` - Item Management
- `/admin/transactions` - Transaction Management
- `/admin/users` - User Management
- `/admin/warehouses` - Warehouse Management

## Features

### Shared Components

- **Sidebar**: Navigation menu with active state highlighting
- **Header**: Search functionality and user profile section
- **Modal**: Reusable modal for forms (Add/Edit operations)
- **AdminLayout**: Wrapper component that includes Sidebar and Header

### Page Features

Each management page includes:
- **Search**: Filter data by search term
- **Filters**: Multiple filter options (status, category, etc.)
- **Pagination**: Navigate through large datasets
- **CRUD Operations**: Create, Read, Update, Delete functionality
- **Statistics Cards**: Display key metrics
- **Data Tables**: Display data with action buttons
- **Export/Print**: Export and print functionality (alerts for now)

## Usage

### Importing Components

```javascript
import { Dashboard, BranchManagement } from './admin';
// or
import Dashboard from './admin/pages/Dashboard';
```

### Using AdminLayout

```javascript
import AdminLayout from '../components/AdminLayout';

const MyPage = () => {
  const handleSearch = (term) => {
    // Handle search
  };

  return (
    <AdminLayout searchPlaceholder="Search..." onSearch={handleSearch}>
      {/* Your page content */}
    </AdminLayout>
  );
};
```

### Using Modal

```javascript
import Modal from '../components/Modal';

const MyComponent = () => {
  const [isOpen, setIsOpen] = useState(false);

  return (
    <Modal isOpen={isOpen} onClose={() => setIsOpen(false)} title="Add Item">
      <form>
        {/* Form content */}
      </form>
    </Modal>
  );
};
```

## Images

Images are stored in `public/images/`:
- `air.png` - AirKing logo
- `pic1.jpg`, `pic2.jpg`, `pic3.jpg`, `pic4.jpg` - Landing page images
- `barcode.png` - Barcode image

## Styling

All CSS files are in the `styles/` folder. Each page imports its specific CSS file:

```javascript
import '../styles/dashboard_air.css';
import '../styles/inventory_management.css';
```

## Notes

- All components use React Hooks (`useState`, `useEffect`)
- Data is currently stored in component state (will be replaced with API calls)
- Modal forms handle both Add and Edit operations
- Search and filter functionality is implemented in each page
- Pagination is handled client-side

## Future Improvements

- Connect to backend API
- Add authentication/authorization
- Implement real export functionality (CSV, PDF)
- Add loading states
- Add error handling
- Add form validation
- Add toast notifications
- Add data persistence
