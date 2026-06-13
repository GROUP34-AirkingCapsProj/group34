import { Suspense, lazy } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './admin/context/AuthContext';
import { SupabaseAuthProvider } from './pwa/context/SupabaseAuthContext';
import RoleRoute from './admin/components/RoleRoute';
import SessionIdleGuard from './admin/components/SessionIdleGuard';
import './App.css';

// Core
const Login = lazy(() => import('./admin/pages/Login'));
const ForgotPassword = lazy(() => import('./admin/pages/ForgotPassword'));
const Dashboard = lazy(() => import('./admin/pages/Dashboard'));

// System Setup
const UserManagement = lazy(() => import('./admin/pages/UserManagement'));
const LocationManagement = lazy(() => import('./admin/pages/LocationManagement'));
const CategoryBrandManagement = lazy(() => import('./admin/pages/CategoryBrandManagement'));

// Procurement
const ItemManagement = lazy(() => import('./admin/pages/ItemManagement'));
const SupplierNetwork = lazy(() => import('./admin/pages/SupplierNetwork'));
const ReceivingManagement = lazy(() => import('./admin/pages/ReceivingManagement'));

// Inventory
const InventoryManagement = lazy(() => import('./admin/pages/InventoryManagement'));
const StockTransferManagement = lazy(() => import('./admin/pages/StockTransferManagement'));
const TransferRequestPage = lazy(() => import('./admin/pages/TransferRequestPage'));
const ISTCreatePage = lazy(() => import('./admin/pages/ISTCreatePage'));
const ISTManagementPage = lazy(() => import('./admin/pages/ISTManagementPage'));
const ISTDetailPage = lazy(() => import('./admin/pages/ISTDetailPage'));
const ISTReceivePage = lazy(() => import('./admin/pages/ISTReceivePage'));
const ISTDispatchPage = lazy(() => import('./admin/pages/ISTDispatchPage'));
const ISTReceiptPage = lazy(() => import('./admin/pages/ISTReceiptPage'));
const IssuanceManagement = lazy(() => import('./admin/pages/IssuanceManagement'));
const AdjustmentManagement = lazy(() => import('./admin/pages/AdjustmentManagement'));
const BarcodeAdjustmentPage = lazy(() => import('./admin/pages/BarcodeAdjustmentPage'));

// Sales & Logistics
const BarcodeScan = lazy(() => import('./admin/pages/BarcodeScan'));
const CustomerManagement = lazy(() => import('./admin/pages/CustomerManagement'));
const TransactionManagement = lazy(() => import('./admin/pages/TransactionManagement'));
const DeliveryReceiptManagement = lazy(() => import('./admin/pages/DeliveryReceiptManagement'));
const PurchaseReturnManagement = lazy(() => import('./admin/pages/PurchaseReturnManagement'));

// Reports & Monitoring
const AnalyticsDashboard = lazy(() => import('./admin/pages/AnalyticsDashboard'));
const SystemReports = lazy(() => import('./admin/pages/SystemReports'));
const AuditTrail = lazy(() => import('./admin/pages/AuditTrail'));
const ProfitLossManagement = lazy(() => import('./admin/pages/ProfitLossManagement'));

// Legacy / other pages
const ApprovalQueue = lazy(() => import('./admin/pages/ApprovalQueue'));
const SystemConfiguration = lazy(() => import('./admin/pages/SystemConfiguration'));
const ArchiveManagement = lazy(() => import('./admin/pages/ArchiveManagement'));
const ArchiveSettingsPage = lazy(() => import('./admin/pages/ArchiveSettingsPage'));
const PORecommendation = lazy(() => import('./admin/pages/PORecommendation'));
const BudgetManagementPage = lazy(() => import('./admin/pages/BudgetManagementPage'));
const PurchaseRequestPage = lazy(() => import('./admin/pages/PurchaseRequestPage'));
const PurchaseOrderPage = lazy(() => import('./admin/pages/PurchaseOrderPage'));
const ComplianceReview = lazy(() => import('./admin/pages/ComplianceReview'));
const AlertsNotices = lazy(() => import('./admin/pages/AlertsNotices'));
const WarrantyManagement = lazy(() => import('./admin/pages/WarrantyManagement'));
const WarrantyClaimPage = lazy(() => import('./admin/pages/WarrantyClaimPage'));
const IssuanceScanPage  = lazy(() => import('./admin/pages/IssuanceScanPage'));

// SA Permission Editor
const RolePermissions       = lazy(() => import('./admin/pages/RolePermissions'));
const UserPermissionOverrides = lazy(() => import('./admin/pages/UserPermissionOverrides'));
const PermissionAuditLog    = lazy(() => import('./admin/pages/PermissionAuditLog'));

// Supabase PWA (parallel to Laravel admin)
const PwaLogin = lazy(() => import('./pwa/pages/PwaLogin'));
const PwaLayout = lazy(() => import('./pwa/components/PwaLayout'));
const PwaProtectedRoute = lazy(() => import('./pwa/components/PwaProtectedRoute'));
const PwaDashboard = lazy(() => import('./pwa/pages/PwaDashboard'));
const PwaScan = lazy(() => import('./pwa/pages/PwaScan'));
const PwaProducts = lazy(() => import('./pwa/pages/PwaProducts'));

function App() {
  return (
    <AuthProvider>
    <SupabaseAuthProvider>
    <Router>
      <SessionIdleGuard />
      <Suspense fallback={<div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100vh', color: 'var(--text-muted)' }}>Loading...</div>}>
        <Routes>
          <Route path="/" element={<Navigate to="/admin/login" replace />} />
          <Route path="/admin" element={<Navigate to="/admin/login" replace />} />
          <Route path="/admin/login" element={<Login />} />
          <Route path="/admin/forgot-password" element={<ForgotPassword />} />

          {/* Supabase PWA inventory (Vercel + Supabase; optional env) */}
          <Route path="/pwa/login" element={<PwaLogin />} />
          <Route
            path="/pwa"
            element={
              <PwaProtectedRoute>
                <PwaLayout />
              </PwaProtectedRoute>
            }
          >
            <Route index element={<Navigate to="dashboard" replace />} />
            <Route path="dashboard" element={<PwaDashboard />} />
            <Route path="scan" element={<PwaScan />} />
            <Route path="products" element={<PwaProducts />} />
          </Route>

          {/* Dashboard */}
          <Route path="/admin/dashboard" element={<RoleRoute><Dashboard /></RoleRoute>} />

          {/* System Setup */}
          <Route path="/admin/users" element={<RoleRoute><UserManagement /></RoleRoute>} />
          <Route path="/admin/locations" element={<RoleRoute><LocationManagement /></RoleRoute>} />
          <Route path="/admin/branches" element={<RoleRoute><LocationManagement /></RoleRoute>} />
          <Route path="/admin/warehouses" element={<RoleRoute><LocationManagement /></RoleRoute>} />
          <Route path="/admin/categories" element={<RoleRoute><CategoryBrandManagement /></RoleRoute>} />
          <Route path="/admin/brands" element={<RoleRoute><CategoryBrandManagement /></RoleRoute>} />

          {/* Procurement */}
          <Route path="/admin/items" element={<RoleRoute><ItemManagement /></RoleRoute>} />
          <Route path="/admin/suppliers" element={<RoleRoute><SupplierNetwork /></RoleRoute>} />
          <Route path="/admin/purchase-orders" element={<RoleRoute><ApprovalQueue initialTab="purchase-orders" /></RoleRoute>} />
          <Route path="/admin/budget-management" element={<RoleRoute><BudgetManagementPage /></RoleRoute>} />
          <Route path="/admin/purchase-requests" element={<RoleRoute><PurchaseRequestPage /></RoleRoute>} />
          <Route path="/admin/draft-po-creator" element={<RoleRoute><PurchaseOrderPage /></RoleRoute>} />
          <Route path="/admin/receivings" element={<RoleRoute><ReceivingManagement /></RoleRoute>} />

          {/* Inventory */}
          <Route path="/admin/inventory" element={<RoleRoute><InventoryManagement /></RoleRoute>} />
          <Route path="/admin/transfers" element={<RoleRoute><StockTransferManagement /></RoleRoute>} />
          {/* /admin/transfer-scan retired — receiving is now handled via IST flow (/admin/ist/:id/receive) */}
          <Route path="/admin/transfer-scan" element={<Navigate to="/admin/ist" replace />} />
          <Route path="/admin/transfer-requests" element={<RoleRoute><TransferRequestPage /></RoleRoute>} />
          <Route path="/admin/issuances" element={<RoleRoute><IssuanceManagement /></RoleRoute>} />
          <Route path="/admin/ist" element={<RoleRoute><ISTManagementPage /></RoleRoute>} />
          <Route path="/admin/ist/create/:requestId" element={<RoleRoute><ISTCreatePage /></RoleRoute>} />
          <Route path="/admin/ist/:id/dispatch" element={<RoleRoute><ISTDispatchPage /></RoleRoute>} />
          <Route path="/admin/ist/:id/receive" element={<RoleRoute><ISTReceivePage /></RoleRoute>} />
          <Route path="/admin/ist/:id/receipt" element={<RoleRoute><ISTReceiptPage /></RoleRoute>} />
          <Route path="/admin/ist/:id" element={<RoleRoute><ISTDetailPage /></RoleRoute>} />
          <Route path="/admin/adjustments" element={<RoleRoute><AdjustmentManagement /></RoleRoute>} />
          <Route path="/admin/barcode-adjustment" element={<RoleRoute><BarcodeAdjustmentPage /></RoleRoute>} />

          {/* Sales & Logistics */}
          <Route path="/admin/scan" element={<RoleRoute><BarcodeScan /></RoleRoute>} />
          <Route path="/admin/inventory-operation" element={<RoleRoute><BarcodeScan /></RoleRoute>} />
          <Route path="/admin/customers" element={<RoleRoute><CustomerManagement /></RoleRoute>} />
          <Route path="/admin/transactions" element={<RoleRoute><TransactionManagement /></RoleRoute>} />
          <Route path="/admin/delivery-receipts" element={<RoleRoute><DeliveryReceiptManagement /></RoleRoute>} />
          <Route path="/admin/purchase-returns" element={<RoleRoute><PurchaseReturnManagement /></RoleRoute>} />

          {/* Reports & Monitoring */}
          <Route path="/admin/analytics" element={<RoleRoute><AnalyticsDashboard /></RoleRoute>} />
          <Route path="/admin/reports" element={<RoleRoute><SystemReports /></RoleRoute>} />
          <Route path="/admin/audit" element={<RoleRoute><AuditTrail /></RoleRoute>} />
          <Route path="/admin/profit-loss" element={<RoleRoute><ProfitLossManagement /></RoleRoute>} />

          {/* Legacy routes */}
          <Route path="/admin/approval-queue" element={<RoleRoute><ApprovalQueue /></RoleRoute>} />
          <Route path="/admin/config" element={<RoleRoute><SystemConfiguration /></RoleRoute>} />
          <Route path="/admin/archive" element={<RoleRoute><ArchiveManagement /></RoleRoute>} />
          <Route path="/admin/archive-settings" element={<RoleRoute><ArchiveSettingsPage /></RoleRoute>} />
          <Route path="/admin/po-recommendations" element={<RoleRoute><PORecommendation /></RoleRoute>} />
          <Route path="/admin/compliance" element={<RoleRoute><ComplianceReview /></RoleRoute>} />
          <Route path="/admin/alerts" element={<RoleRoute><AlertsNotices /></RoleRoute>} />
          <Route path="/admin/notifications" element={<RoleRoute><Navigate to="/admin/alerts" replace /></RoleRoute>} />
          <Route path="/admin/warranty" element={<RoleRoute><WarrantyManagement /></RoleRoute>} />
          <Route path="/admin/warranties" element={<RoleRoute><WarrantyManagement /></RoleRoute>} />
          <Route path="/admin/warranty-claims" element={<RoleRoute><WarrantyClaimPage /></RoleRoute>} />
          <Route path="/admin/issuance-scan" element={<RoleRoute><IssuanceScanPage /></RoleRoute>} />

          {/* SA Permission Editor */}
          <Route path="/admin/roles" element={<RoleRoute><RolePermissions /></RoleRoute>} />
          <Route path="/admin/user-permissions" element={<RoleRoute><UserPermissionOverrides /></RoleRoute>} />
          <Route path="/admin/permission-audit" element={<RoleRoute><PermissionAuditLog /></RoleRoute>} />
        </Routes>
      </Suspense>
    </Router>
    </SupabaseAuthProvider>
    </AuthProvider>
  );
}

export default App;
