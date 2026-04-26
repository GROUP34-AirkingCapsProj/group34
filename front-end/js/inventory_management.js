// Menu items data
var menuItems = [
    { name: 'Dashboard', active: false },
    { name: 'User Management', active: false },
    { name: 'Branch Management', active: false },
    { name: 'Warehouse Management', active: false },
    { name: 'Item Management', active: false },
    { name: 'Inventory Management', active: true },
    { name: 'Transaction Management', active: false },
    { name: 'Sales Management', active: false },
    { name: 'Purchase Management', active: false },
    { name: 'Restock Management', active: false },
    { name: 'Transfer Management', active: false },
    { name: 'Warranty & Repairs', active: false },
    { name: 'Returns Management', active: false },
    { name: 'Document Management', active: false },
    { name: 'Reporting', active: false },
    { name: 'Audit & Compliance', active: false },
    { name: 'Settings', active: false }
];

// Generate navigation menu
function generateNav() {
    var navMenu = document.getElementById('navMenu');
    var html = '';
    
    for (var i = 0; i < menuItems.length; i++) {
        var activeClass = menuItems[i].active ? 'active' : '';
        html += '<a href="#" class="nav-item ' + activeClass + '">';
        html += '<span class="icon">▸</span>';
        html += '<span>' + menuItems[i].name + '</span>';
        html += '</a>';
    }
    
    navMenu.innerHTML = html;
}

// Generate header right section
function generateHeader() {
    var headerRight = document.getElementById('headerRight');
    var html = '';
    
    html += '<span class="role-badge">System Admin</span>';
    html += '<button class="icon-btn" id="notifBtn">Bell</button>';
    html += '<button class="icon-btn" id="settingsBtn">Settings</button>';
    html += '<div class="user-profile">';
    html += '<img src="../../images/air.png" alt="User">';
    html += '<span>Admin</span>';
    html += '</div>';
    html += '<a href="../../frontend/html/airking_login.html" class="logout-btn">Logout</a>'; 
    
    headerRight.innerHTML = html;
    
    document.getElementById('notifBtn').addEventListener('click', function() {
        alert('You have 3 new notifications');
    });
    
    document.getElementById('settingsBtn').addEventListener('click', function() {
        alert('Opening settings...');
    });
}

// Sample inventory data
var inventoryData = [
    { id: 1, itemName: 'AirKing Pro Laptop', sku: 'AK-LAP-001', category: 'Electronics', supplier: 'TechSupplier Inc.', description: '15-inch high-performance laptop', unitPrice: 1250.00, quantity: 50, reorderLevel: 10, warehouseLocation: 'Main Warehouse CDO', status: 'In Stock' },
    { id: 2, itemName: 'Ergonomic Office Chair', sku: 'AK-FUR-045', category: 'Furniture', supplier: 'FurniWell', description: 'Black mesh back office chair', unitPrice: 150.50, quantity: 8, reorderLevel: 15, warehouseLocation: 'CDO Storage Facility', status: 'Low Stock' },
    { id: 3, itemName: 'AirKing Fridge 2-Door', sku: 'AK-APL-012', category: 'Appliances', supplier: 'HomeGoods Co.', description: '250L frost-free refrigerator', unitPrice: 899.99, quantity: 0, reorderLevel: 5, warehouseLocation: 'Davao Main Warehouse', status: 'Out of Stock' },
    { id: 4, itemName: 'Wireless Mouse', sku: 'AK-ACC-078', category: 'Accessories', supplier: 'TechSupplier Inc.', description: 'Bluetooth 5.0 mouse', unitPrice: 25.00, quantity: 200, reorderLevel: 50, warehouseLocation: 'Main Warehouse CDO', status: 'In Stock' },
    { id: 5, itemName: 'Standing Desk', sku: 'AK-FUR-046', category: 'Furniture', supplier: 'FurniWell', description: 'Adjustable height standing desk', unitPrice: 450.00, quantity: 30, reorderLevel: 10, warehouseLocation: 'Tagum Warehouse', status: 'In Stock' },
    { id: 6, itemName: 'AirKing Microwave', sku: 'AK-APL-013', category: 'Appliances', supplier: 'HomeGoods Co.', description: '30L solo microwave oven', unitPrice: 120.00, quantity: 75, reorderLevel: 20, warehouseLocation: 'Davao Main Warehouse', status: 'In Stock' }
];

var currentPage = 1;
var rowsPerPage = 5;
var filteredInventory = inventoryData;

// Generate statistics
function generateInventoryStats() {
    var statsContainer = document.getElementById('inventoryStats');
    var totalItems = inventoryData.length;
    var lowStockItems = inventoryData.filter(function(item) { return item.status === 'Low Stock'; }).length;
    var totalValue = inventoryData.reduce(function(sum, item) { return sum + (item.unitPrice * item.quantity); }, 0).toFixed(2);
    var inStockItems = inventoryData.filter(function(item) { return item.status === 'In Stock'; }).length;
    
    var html = '';
html += '<div class="stat-card red"><div class="stat-info"><h3>' + totalItems + '</h3><p>Total Items</p></div></div>';
html += '<div class="stat-card yellow"><div class="stat-info"><h3>' + lowStockItems + '</h3><p>Low Stock Items</p></div></div>';
html += '<div class="stat-card red"><div class="stat-info"><h3>₱' + totalValue + '</h3><p>Total Inventory Value</p></div></div>';
html += '<div class="stat-card yellow"><div class="stat-info"><h3>' + inStockItems + '</h3><p>In Stock Items</p></div></div>';

statsContainer.innerHTML = html;

}

// Generate table
function generateInventoryTable() {
    var tableHead = document.getElementById('tableHead');
    var tableBody = document.getElementById('tableBody');
    
    var headHtml = '<tr>';
    var headers = ['ID', 'Item Name', 'SKU', 'Category', 'Quantity', 'Unit Price', 'Status', 'Actions'];
    for (var i = 0; i < headers.length; i++) {
        headHtml += '<th>' + headers[i] + '</th>';
    }
    headHtml += '</tr>';
    tableHead.innerHTML = headHtml;
    
    var start = (currentPage - 1) * rowsPerPage;
    var end = start + rowsPerPage;
    var paginatedInventory = filteredInventory.slice(start, end);
    
    var bodyHtml = '';
    for (var i = 0; i < paginatedInventory.length; i++) {
        var item = paginatedInventory[i];
        var statusClass = '';
        if (item.status === 'In Stock') statusClass = 'status-active';
        else if (item.status === 'Low Stock') statusClass = 'status-low-stock';
        else if (item.status === 'Out of Stock') statusClass = 'status-out-of-stock';

        
        bodyHtml += '<tr>';
        bodyHtml += '<td>' + item.id + '</td>';
        bodyHtml += '<td>' + item.itemName + '</td>';
        bodyHtml += '<td>' + item.sku + '</td>';
        bodyHtml += '<td>' + item.category + '</td>';
        bodyHtml += '<td>' + item.quantity + '</td>';
        bodyHtml += '<td>₱' + item.unitPrice.toFixed(2) + '</td>';
        bodyHtml += '<td><span class="' + statusClass + '">' + item.status + '</span></td>';
        bodyHtml += '<td>';
        bodyHtml += '<div class="table-actions">';
        bodyHtml += '<button class="btn-view" data-id="' + item.id + '">View</button>';
        bodyHtml += '<button class="btn-edit" data-id="' + item.id + '">Edit</button>';
        bodyHtml += '<button class="btn-delete" data-id="' + item.id + '">Delete</button>';
        bodyHtml += '</div>';
        bodyHtml += '</td>';
        bodyHtml += '</tr>';
    }
    
    if (paginatedInventory.length === 0) {
        bodyHtml = '<tr><td colspan="8" style="text-align: center; color: #999;">No items found</td></tr>';
    }
    
    tableBody.innerHTML = bodyHtml;
    
    addTableEventListeners();
    generatePagination();
}

// Add event listeners
function addTableEventListeners() {
    var viewBtns = document.querySelectorAll('.btn-view');
    viewBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var item = inventoryData.find(function(i) { return i.id == id; });
            if (item) {
                var info = 'Item Details:\n\n';
                info += 'Name: ' + item.itemName + '\n';
                info += 'SKU: ' + item.sku + '\n';
                info += 'Category: ' + item.category + '\n';
                info += 'Supplier: ' + item.supplier + '\n';
                info += 'Description: ' + item.description + '\n';
                info += 'Price: ₱' + item.unitPrice.toFixed(2) + '\n';
                info += 'Quantity: ' + item.quantity + '\n';
                info += 'Warehouse: ' + item.warehouseLocation + '\n';
                info += 'Status: ' + item.status;
                alert(info);
            }
        });
    });
    
    var editBtns = document.querySelectorAll('.btn-edit');
    editBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            editInventory(id);
        });
    });
    
    var deleteBtns = document.querySelectorAll('.btn-delete');
    deleteBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            deleteInventory(id);
        });
    });
}

// Generate pagination
function generatePagination() {
    var pagination = document.getElementById('pagination');
    var totalPages = Math.ceil(filteredInventory.length / rowsPerPage);
    
    var html = '';
    html += '<button ' + (currentPage === 1 ? 'disabled' : '') + ' id="prevPage">Previous</button>';
    
    for (var i = 1; i <= totalPages; i++) {
        var activeClass = i === currentPage ? 'active' : '';
        html += '<button class="' + activeClass + '" data-page="' + i + '">' + i + '</button>';
    }
    
    html += '<button ' + (currentPage === totalPages ? 'disabled' : '') + ' id="nextPage">Next</button>';
    
    pagination.innerHTML = html;
    
    document.getElementById('prevPage').addEventListener('click', function() {
        if (currentPage > 1) {
            currentPage--;
            generateInventoryTable();
        }
    });
    
    document.getElementById('nextPage').addEventListener('click', function() {
        var totalPages = Math.ceil(filteredInventory.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            generateInventoryTable();
        }
    });
    
    var pageBtns = pagination.querySelectorAll('button[data-page]');
    pageBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            currentPage = parseInt(this.getAttribute('data-page'));
            generateInventoryTable();
        });
    });
}

// Modal functions
var modal = document.getElementById('inventoryModal');
var addInventoryBtn = document.getElementById('addInventoryBtn');
var closeModal = document.getElementById('closeModal');
var cancelBtn = document.getElementById('cancelBtn');
var inventoryForm = document.getElementById('inventoryForm');

addInventoryBtn.addEventListener('click', function() {
    document.getElementById('modalTitle').textContent = 'Add New Item';
    inventoryForm.reset();
    modal.style.display = 'block';
});

closeModal.addEventListener('click', function() {
    modal.style.display = 'none';
});

cancelBtn.addEventListener('click', function() {
    modal.style.display = 'none';
});

window.addEventListener('click', function(e) {
    if (e.target == modal) {
        modal.style.display = 'none';
    }
});

// Form submission
inventoryForm.addEventListener('submit', function(e) {
    e.preventDefault();
    
    var editingId = inventoryForm.getAttribute('data-editing-id');
    
    if (editingId) {
        // Update existing item
        var index = inventoryData.findIndex(function(i) { return i.id == editingId; });
        if (index !== -1) {
            inventoryData[index] = {
                id: parseInt(editingId),
                itemName: document.getElementById('itemName').value,
                sku: document.getElementById('sku').value,
                category: document.getElementById('category').value,
                supplier: document.getElementById('supplier').value,
                description: document.getElementById('description').value,
                unitPrice: parseFloat(document.getElementById('unitPrice').value),
                quantity: parseInt(document.getElementById('quantity').value),
                reorderLevel: parseInt(document.getElementById('reorderLevel').value),
                warehouseLocation: document.getElementById('warehouseLocation').value,
                status: document.getElementById('status').value
            };
            alert('Item updated successfully!');
        }
        inventoryForm.removeAttribute('data-editing-id');
    } else {
        // Add new item
        var newItem = {
            id: inventoryData.length + 1,
            itemName: document.getElementById('itemName').value,
            sku: document.getElementById('sku').value,
            category: document.getElementById('category').value,
            supplier: document.getElementById('supplier').value,
            description: document.getElementById('description').value,
            unitPrice: parseFloat(document.getElementById('unitPrice').value),
            quantity: parseInt(document.getElementById('quantity').value),
            reorderLevel: parseInt(document.getElementById('reorderLevel').value),
            warehouseLocation: document.getElementById('warehouseLocation').value,
            status: document.getElementById('status').value
        };
        inventoryData.push(newItem);
        alert('Item added successfully!');
    }
    
    filteredInventory = inventoryData;
    generateInventoryStats();
    generateInventoryTable();
    modal.style.display = 'none';
});

// Edit inventory
function editInventory(id) {
    var item = inventoryData.find(function(i) { return i.id == id; });
    if (item) {
        document.getElementById('modalTitle').textContent = 'Edit Item';
        document.getElementById('itemName').value = item.itemName;
        document.getElementById('sku').value = item.sku;
        document.getElementById('category').value = item.category;
        document.getElementById('supplier').value = item.supplier;
        document.getElementById('description').value = item.description;
        document.getElementById('unitPrice').value = item.unitPrice;
        document.getElementById('quantity').value = item.quantity;
        document.getElementById('reorderLevel').value = item.reorderLevel;
        document.getElementById('warehouseLocation').value = item.warehouseLocation;
        document.getElementById('status').value = item.status;
        inventoryForm.setAttribute('data-editing-id', id);
        modal.style.display = 'block';
    }
}

// Delete inventory
function deleteInventory(id) {
    if (confirm('Are you sure you want to delete this item?')) {
        inventoryData = inventoryData.filter(function(i) { return i.id != id; });
        filteredInventory = inventoryData;
        generateInventoryStats();
        generateInventoryTable();
        alert('Item deleted successfully!');
    }
}

// Filter functions
document.getElementById('categoryFilter').addEventListener('change', applyFilters);
document.getElementById('warehouseFilter').addEventListener('change', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);

function applyFilters() {
    var categoryFilter = document.getElementById('categoryFilter').value;
    var warehouseFilter = document.getElementById('warehouseFilter').value;
    var statusFilter = document.getElementById('statusFilter').value;
    
    filteredInventory = inventoryData.filter(function(item) {
        var matchCategory = !categoryFilter || item.category === categoryFilter;
        var matchWarehouse = !warehouseFilter || item.warehouseLocation === warehouseFilter;
        var matchStatus = !statusFilter || item.status === statusFilter;
        return matchCategory && matchWarehouse && matchStatus;
    });
    
    currentPage = 1;
    generateInventoryTable();
}

document.getElementById('resetFilter').addEventListener('click', function() {
    document.getElementById('categoryFilter').value = '';
    document.getElementById('warehouseFilter').value = '';
    document.getElementById('statusFilter').value = '';
    filteredInventory = inventoryData;
    currentPage = 1;
    generateInventoryTable();
});

// Search functionality
document.getElementById('searchBtn').addEventListener('click', function() {
    var searchTerm = document.getElementById('searchInput').value.toLowerCase();
    if (searchTerm) {
        filteredInventory = inventoryData.filter(function(item) {
            return item.itemName.toLowerCase().includes(searchTerm) ||
                   item.sku.toLowerCase().includes(searchTerm);
        });
        currentPage = 1;
        generateInventoryTable();
    }
});

document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('searchBtn').click();
    }
});

// Export and Print
document.getElementById('exportBtn').addEventListener('click', function() {
    alert('Exporting inventory data...');
});

document.getElementById('printBtn').addEventListener('click', function() {
    window.print();
});

// Initialize on page load
window.addEventListener('load', function() {
    generateNav();
    generateHeader();
    generateInventoryStats();
    generateInventoryTable();
    
    setTimeout(function() {
        var navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                // --- NAVIGATION FIX ---
                // Get the text from the second span, which contains the page name
                var text = this.querySelector('span:last-child').textContent.trim();
                // --- END OF FIX ---
                
                // Set active state for the clicked item
                navItems.forEach(function(nav) { nav.classList.remove('active'); });
                this.classList.add('active');
                
                // --- FIX: Corrected all navigation paths ---
                switch (text) {
                    case 'Dashboard':
                        window.location.href = '../html/dashboard_air.html';
                        break;
                    case 'User Management':
                        window.location.href = '../html/user_management.html';
                        break;
                    case 'Branch Management':
                        window.location.href = '../html/branch_management.html';
                        break;
                    case 'Warehouse Management':
                        window.location.href = '../html/warehouse_management.html';
                        break;
                    case 'Item Management':
                        window.location.href = '../html/item_management.html';
                        break;
                    case 'Transaction Management':
                        window.location.href = '../html/transaction_management.html';
                        break;
                    case 'Inventory Management':
                        // Already on the page, do nothing
                        break;
                    default:
                        alert('Module "' + text + '" is under development');
                        break;
                }
            });
        });
    }, 100);
});