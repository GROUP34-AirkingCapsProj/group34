import { Suspense, lazy } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './admin/context/AuthContext';
import { SupabaseAuthProvider } from './pwa/context/SupabaseAuthContext';
import RoleRoute from './admin/components/RoleRoute';
import LegacyAdminRedirect from './admin/components/LegacyAdminRedirect';
import ErrorBoundary from './admin/components/ErrorBoundary';
import SessionIdleGuard from './admin/components/SessionIdleGuard';
import Loader from './admin/components/Loader';
import './admin/styles/airking_ds.css';
import './App.css';

// Core
const Login = lazy(() => import('./admin/pages/Login'));
const ForgotPassword = lazy(() => import('./admin/pages/ForgotPassword'));
const ChangePassword = lazy(() => import('./admin/pages/ChangePassword'));
const Dashboard = lazy(() => import('./admin/pages/Dashboard'));

// System Setup
const LocationManagement = lazy(() => import('./admin/pages/LocationManagement'));
const LookupManagement = lazy(() => import('./admin/pages/LookupManagement'));

// Procurement
const ItemManagement = lazy(() => import('./admin/pages/ItemManagement'));
const SupplierNetwork = lazy(() => import('./admin/pages/SupplierNetwork'));
const SetManagementPage = lazy(() => import('./admin/pages/SetManagementPage'));
const AirconModelsPage = lazy(() => import('./admin/pages/AirconModelsPage'));
const IncidentWorkspace = lazy(() => import('./admin/pages/IncidentWorkspace'));

// Inventory
const InventoryManagement = lazy(() => import('./admin/pages/InventoryManagement'));
const BarcodePrintingPage = lazy(() => import('./admin/pages/BarcodePrintingPage'));
const StockTransferManagement = lazy(() => import('./admin/pages/StockTransferManagement'));
const TransfersPage = lazy(() => import('./admin/pages/TransfersPage'));
const TransferRequestPage = lazy(() => import('./admin/pages/TransferRequestPage'));
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
const ReportsAnalytics = lazy(() => import('./admin/pages/ReportsAnalytics'));
const AuditTrail = lazy(() => import('./admin/pages/AuditTrail'));
const AuditEvidenceExplorer = lazy(() => import('./admin/pages/AuditEvidenceExplorer'));
const ProfitLossManagement = lazy(() => import('./admin/pages/ProfitLossManagement'));

// Legacy / other pages
const SystemConfiguration = lazy(() => import('./admin/pages/SystemConfiguration'));
const ArchiveManagement = lazy(() => import('./admin/pages/ArchiveManagement'));
const ArchiveSettingsPage = lazy(() => import('./admin/pages/ArchiveSettingsPage'));
const PurchaseRequestPage = lazy(() => import('./admin/pages/PurchaseRequestPage'));
const PurchaseOrderPage = lazy(() => import('./admin/pages/PurchaseOrderPage'));
const ComplianceReview = lazy(() => import('./admin/pages/ComplianceReview'));
const AlertsNotices = lazy(() => import('./admin/pages/AlertsNotices'));
const WarrantyManagement = lazy(() => import('./admin/pages/WarrantyManagement'));
const WarrantyClaimPage = lazy(() => import('./admin/pages/WarrantyClaimPage'));
const IssuanceScanPage  = lazy(() => import('./admin/pages/IssuanceScanPage'));
const AccountSettingsPage = lazy(() => import('./admin/pages/AccountSettingsPage'));

// SA Permission Editor
const AccessControlPage     = lazy(() => import('./admin/pages/AccessControlPage'));
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
      {/* Catches a render-time throw in any page. Without it React unmounts the
          whole tree and the user gets a white screen with nothing to report. */}
      <ErrorBoundary>
      <Suspense fallback={<Loader variant="page" />}>
        <Routes>
          <Route path="/" element={<Navigate to="/login" replace />} />
          {/* Legacy /admin/* URLs — see LegacyAdminRedirect. Also resolves the
              deep links already stored in notification rows. */}
          <Route path="/admin/*" element={<LegacyAdminRedirect />} />
          <Route path="/login" element={<Login />} />
          <Route path="/forgot-password" element={<ForgotPassword />} />
          {/* Outside RoleRoute: the guard inside RoleRoute redirects here, so
              wrapping this route in it would loop. */}
          <Route path="/change-password" element={<ChangePassword />} />

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
          <Route path="/dashboard" element={<RoleRoute><Dashboard /></RoleRoute>} />
          <Route path="/account" element={<RoleRoute><AccountSettingsPage /></RoleRoute>} />

          {/* System Setup */}
          <Route path="/locations" element={<RoleRoute><LocationManagement /></RoleRoute>} />
          <Route path="/branches" element={<RoleRoute><LocationManagement /></RoleRoute>} />
          <Route path="/warehouses" element={<RoleRoute><LocationManagement /></RoleRoute>} />
          <Route path="/categories" element={<RoleRoute><LookupManagement /></RoleRoute>} />
          <Route path="/brands" element={<RoleRoute><LookupManagement /></RoleRoute>} />

          {/* Procurement */}
          <Route path="/items" element={<RoleRoute><ItemManagement /></RoleRoute>} />
          <Route path="/suppliers" element={<RoleRoute><SupplierNetwork /></RoleRoute>} />
          <Route path="/sets" element={<RoleRoute><SetManagementPage /></RoleRoute>} />
          <Route path="/aircon-models" element={<RoleRoute><AirconModelsPage /></RoleRoute>} />
          <Route path="/incidents" element={<RoleRoute><IncidentWorkspace /></RoleRoute>} />
          <Route path="/incident-items" element={<Navigate to="/incidents?tab=items" replace />} />
          {/* Held Stock became a read-only register when the inspection took
              over its Dispose action. Bookmarks and any link still pointing at
              the old path land on the register rather than a blank page. */}
          <Route path="/held-stock" element={<Navigate to="/incident-items" replace />} />
          <Route path="/inspections" element={<Navigate to="/incidents?tab=inspections" replace />} />
          <Route path="/purchase-orders" element={<Navigate to="/draft-po-creator" replace />} />
          <Route path="/purchase-requests" element={<RoleRoute><PurchaseRequestPage /></RoleRoute>} />
          <Route path="/draft-po-creator" element={<RoleRoute><PurchaseOrderPage /></RoleRoute>} />

          {/* Inventory */}
          <Route path="/inventory" element={<RoleRoute><InventoryManagement /></RoleRoute>} />
          <Route path="/barcode-printing" element={<RoleRoute><BarcodePrintingPage /></RoleRoute>} />
          <Route path="/transfers" element={<RoleRoute><TransfersPage /></RoleRoute>} />
          {/* /transfer-scan and the former /ist pages are both retired. Receiving
              now lives in TransfersPage's receive bucket, which is where the
              working ReceivePanel/DispatchPanel/VerifyPanel actually are. */}
          <Route path="/transfer-scan" element={<Navigate to="/transfers" replace />} />
          <Route path="/ist/*" element={<Navigate to="/transfers" replace />} />
          <Route path="/transfer-requests" element={<RoleRoute><TransferRequestPage /></RoleRoute>} />
          <Route path="/issuances" element={<RoleRoute><IssuanceManagement /></RoleRoute>} />
          <Route path="/adjustments" element={<RoleRoute><AdjustmentManagement /></RoleRoute>} />
          <Route path="/barcode-adjustment" element={<RoleRoute><BarcodeAdjustmentPage /></RoleRoute>} />

          {/* Sales & Logistics */}
          <Route path="/scan" element={<RoleRoute><BarcodeScan /></RoleRoute>} />
          <Route path="/inventory-operation" element={<RoleRoute><BarcodeScan /></RoleRoute>} />
          <Route path="/customers" element={<RoleRoute><CustomerManagement /></RoleRoute>} />
          <Route path="/transactions" element={<RoleRoute><TransactionManagement /></RoleRoute>} />
          <Route path="/delivery-receipts" element={<RoleRoute><DeliveryReceiptManagement /></RoleRoute>} />
          <Route path="/purchase-returns" element={<RoleRoute><PurchaseReturnManagement /></RoleRoute>} />

          {/* Reports & Monitoring */}
          <Route path="/analytics" element={<RoleRoute><Navigate to="/reports?tab=analytics" replace /></RoleRoute>} />
          <Route path="/reports" element={<RoleRoute><ReportsAnalytics /></RoleRoute>} />
          <Route path="/audit" element={<RoleRoute><AuditTrail /></RoleRoute>} />
          <Route path="/audit-evidence" element={<RoleRoute><AuditEvidenceExplorer /></RoleRoute>} />
          <Route path="/profit-loss" element={<RoleRoute><ProfitLossManagement /></RoleRoute>} />

          {/* Legacy routes */}
          <Route path="/approval-queue" element={<Navigate to="/draft-po-creator" replace />} />
          <Route path="/config" element={<RoleRoute><SystemConfiguration /></RoleRoute>} />
          <Route path="/archive" element={<RoleRoute><ArchiveManagement /></RoleRoute>} />
          <Route path="/archive-settings" element={<RoleRoute><ArchiveSettingsPage /></RoleRoute>} />
          <Route path="/backup" element={<RoleRoute><Navigate to="/config?tab=backup" replace /></RoleRoute>} />
          <Route path="/compliance" element={<RoleRoute><ComplianceReview /></RoleRoute>} />
          <Route path="/alerts" element={<RoleRoute><AlertsNotices /></RoleRoute>} />
          <Route path="/notifications" element={<RoleRoute><Navigate to="/alerts" replace /></RoleRoute>} />
          <Route path="/warranty" element={<RoleRoute><WarrantyManagement /></RoleRoute>} />
          <Route path="/warranties" element={<RoleRoute><WarrantyManagement /></RoleRoute>} />
          <Route path="/warranty-claims" element={<RoleRoute><WarrantyClaimPage /></RoleRoute>} />
          <Route path="/issuance-scan" element={<RoleRoute><IssuanceScanPage /></RoleRoute>} />

          {/* SA Permission Editor */}
          <Route path="/roles" element={<RoleRoute><AccessControlPage /></RoleRoute>} />
          <Route path="/user-permissions" element={<RoleRoute><AccessControlPage /></RoleRoute>} />
          <Route path="/permission-audit" element={<RoleRoute><PermissionAuditLog /></RoleRoute>} />
        </Routes>
      </Suspense>
      </ErrorBoundary>
    </Router>
    </SupabaseAuthProvider>
    </AuthProvider>
  );
}

export default App;
