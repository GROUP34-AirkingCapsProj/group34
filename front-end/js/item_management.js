// Menu items data
var menuItems = [
    { name: 'Dashboard', active: false },
    { name: 'User Management', active: false },
    { name: 'Branch Management', active: false },
    { name: 'Warehouse Management', active: false },
    { name: 'Item Management', active: true },
    { name: 'Inventory Management', active: false },
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

// Generate header
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

// Sample item data
var itemsData = [
    { id: 1, name: 'TCL 1.5HP Split Type Inverter', code: 'TCL-AC-001', category: 'Air Conditioner Units', brand: 'TCL', unitPrice: 18500, unit: 'Unit', reorderLevel: 5, supplier: 'TCL Philippines', status: 'Active' },
    { id: 2, name: 'Samsung 2.0HP Window Type', code: 'SAM-AC-002', category: 'Air Conditioner Units', brand: 'Samsung', unitPrice: 22000, unit: 'Unit', reorderLevel: 3, supplier: 'Samsung Corp', status: 'Active' },
    { id: 3, name: 'Air Filter Universal', code: 'FIL-UNI-001', category: 'Filters', brand: 'Haier', unitPrice: 450, unit: 'Piece', reorderLevel: 20, supplier: 'Haier Supplies', status: 'Active' },
    { id: 4, name: 'Compressor 1.5HP', code: 'COM-150-001', category: 'Compressors', brand: 'LG', unitPrice: 8500, unit: 'Unit', reorderLevel: 5, supplier: 'LG Parts Center', status: 'Active' },
    { id: 5, name: 'Cooling Coil Copper', code: 'COI-COP-001', category: 'Coils', brand: 'Carrier', unitPrice: 3200, unit: 'Piece', reorderLevel: 10, supplier: 'Carrier Parts', status: 'Active' },
    { id: 6, name: 'Fan Motor 1/4HP', code: 'MOT-025-001', category: 'Motors', brand: 'Midea', unitPrice: 1850, unit: 'Unit', reorderLevel: 8, supplier: 'Midea Distributor', status: 'Active' },
    { id: 7, name: 'Digital Thermostat', code: 'CTL-THR-001', category: 'Controls', brand: 'Koppel', unitPrice: 950, unit: 'Piece', reorderLevel: 15, supplier: 'Koppel Inc', status: 'Active' },
    { id: 8, name: 'Remote Control Universal', code: 'ACC-REM-001', category: 'Accessories', brand: 'CHiQ', unitPrice: 350, unit: 'Piece', reorderLevel: 25, supplier: 'CHiQ Electronics', status: 'Inactive' }
];

var currentPage = 1;
var rowsPerPage = 5;
var filteredItems = itemsData;

// Generate statistics
function generateStats() {
    var statsContainer = document.getElementById('itemStats');
    var totalItems = itemsData.length;
    var activeItems = itemsData.filter(function(i) { return i.status === 'Active'; }).length;
    var totalValue = itemsData.reduce(function(sum, i) { return sum + i.unitPrice; }, 0);
    var categories = [...new Set(itemsData.map(function(i) { return i.category; }))].length;
    
  var html = '';
html += '<div class="stat-card red"><div class="stat-info"><h3>' + totalItems + '</h3><p>Total Items</p></div></div>';
html += '<div class="stat-card yellow"><div class="stat-info"><h3>' + activeItems + '</h3><p>Active Items</p></div></div>';
html += '<div class="stat-card red"><div class="stat-info"><h3>₱' + totalValue.toLocaleString() + '</h3><p>Total Value</p></div></div>';
html += '<div class="stat-card yellow"><div class="stat-info"><h3>' + categories + '</h3><p>Categories</p></div></div>';

statsContainer.innerHTML = html;

}

// Generate table
function generateItemTable() {
    var tableHead = document.getElementById('tableHead');
    var tableBody = document.getElementById('tableBody');
    
    var headHtml = '<tr>';
    var headers = ['ID', 'Item Code', 'Item Name', 'Category', 'Brand', 'Unit Price', 'Unit', 'Status', 'Actions'];
    for (var i = 0; i < headers.length; i++) {
        headHtml += '<th>' + headers[i] + '</th>';
    }
    headHtml += '</tr>';
    tableHead.innerHTML = headHtml;
    
    var start = (currentPage - 1) * rowsPerPage;
    var end = start + rowsPerPage;
    var paginatedItems = filteredItems.slice(start, end);
    
    var bodyHtml = '';
    for (var i = 0; i < paginatedItems.length; i++) {
        var item = paginatedItems[i];
        var statusClass = item.status === 'Active' ? 'status-active' : 'status-inactive';
        
        bodyHtml += '<tr>';
        bodyHtml += '<td>' + item.id + '</td>';
        bodyHtml += '<td>' + item.code + '</td>';
        bodyHtml += '<td>' + item.name + '</td>';
        bodyHtml += '<td>' + item.category + '</td>';
        bodyHtml += '<td>' + item.brand + '</td>';
        bodyHtml += '<td>₱' + item.unitPrice.toLocaleString() + '</td>';
        bodyHtml += '<td>' + item.unit + '</td>';
        bodyHtml += '<td><span class="' + statusClass + '">' + item.status + '</span></td>';
        bodyHtml += '<td>';
        bodyHtml += '<div class="table-actions">'; // Wrapper for button spacing
        bodyHtml += '<button class="btn-view" data-id="' + item.id + '">View</button>';
        bodyHtml += '<button class="btn-edit" data-id="' + item.id + '">Edit</button>';
        bodyHtml += '<button class="btn-delete" data-id="' + item.id + '">Delete</button>';
        bodyHtml += '</div>';
        bodyHtml += '</td>';
        bodyHtml += '</tr>';
    }
    
    if (paginatedItems.length === 0) {
        bodyHtml = '<tr><td colspan="9" style="text-align: center; color: #999;">No items found</td></tr>';
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
            var item = itemsData.find(function(i) { return i.id == id; });
            if (item) {
                var info = 'Item Details:\n\n';
                info += 'Code: ' + item.code + '\n';
                info += 'Name: ' + item.name + '\n';
                info += 'Category: ' + item.category + '\n';
                info += 'Brand: ' + item.brand + '\n';
                info += 'Unit Price: ₱' + item.unitPrice.toLocaleString() + '\n';
                info += 'Unit: ' + item.unit + '\n';
                info += 'Reorder Level: ' + item.reorderLevel + '\n';
                info += 'Supplier: ' + item.supplier + '\n';
                info += 'Status: ' + item.status;
                alert(info);
            }
        });
    });
    
    var editBtns = document.querySelectorAll('.btn-edit');
    editBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            editItem(id);
        });
    });
    
    var deleteBtns = document.querySelectorAll('.btn-delete');
    deleteBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            deleteItem(id);
        });
    });
}

// Generate pagination
function generatePagination() {
    var pagination = document.getElementById('pagination');
    var totalPages = Math.ceil(filteredItems.length / rowsPerPage);
    
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
            generateItemTable();
        }
    });
    
    document.getElementById('nextPage').addEventListener('click', function() {
        var totalPages = Math.ceil(filteredItems.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            generateItemTable();
        }
    });
    
    var pageBtns = pagination.querySelectorAll('button[data-page]');
    pageBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            currentPage = parseInt(this.getAttribute('data-page'));
            generateItemTable();
        });
    });
}

// Modal functions
var modal = document.getElementById('itemModal');
var addItemBtn = document.getElementById('addItemBtn');
var closeModal = document.getElementById('closeModal');
var cancelBtn = document.getElementById('cancelBtn');
var itemForm = document.getElementById('itemForm');

addItemBtn.addEventListener('click', function() {
    document.getElementById('modalTitle').textContent = 'Add New Item';
    itemForm.reset();
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
itemForm.addEventListener('submit', function(e) {
    e.preventDefault();
    
    var editingId = itemForm.getAttribute('data-editing-id');
    
    if (editingId) {
        var index = itemsData.findIndex(function(i) { return i.id == editingId; });
        if (index !== -1) {
            itemsData[index] = {
                id: parseInt(editingId),
                name: document.getElementById('itemName').value,
                code: document.getElementById('itemCode').value,
                category: document.getElementById('category').value,
                brand: document.getElementById('brand').value,
                unitPrice: parseFloat(document.getElementById('unitPrice').value),
                unit: document.getElementById('unit').value,
                reorderLevel: parseInt(document.getElementById('reorderLevel').value),
                supplier: document.getElementById('supplier').value,
                status: document.getElementById('status').value
            };
            alert('Item updated successfully!');
        }
        itemForm.removeAttribute('data-editing-id');
    } else {
        var newItem = {
            id: itemsData.length + 1,
            name: document.getElementById('itemName').value,
            code: document.getElementById('itemCode').value,
            category: document.getElementById('category').value,
            brand: document.getElementById('brand').value,
            unitPrice: parseFloat(document.getElementById('unitPrice').value),
            unit: document.getElementById('unit').value,
            reorderLevel: parseInt(document.getElementById('reorderLevel').value),
            supplier: document.getElementById('supplier').value,
            status: document.getElementById('status').value
        };
        itemsData.push(newItem);
        alert('Item added successfully!');
    }
    
    filteredItems = itemsData;
    generateStats();
    generateItemTable();
    modal.style.display = 'none';
});

// Edit item
function editItem(id) {
    var item = itemsData.find(function(i) { return i.id == id; });
    if (item) {
        document.getElementById('modalTitle').textContent = 'Edit Item';
        document.getElementById('itemName').value = item.name;
        document.getElementById('itemCode').value = item.code;
        document.getElementById('category').value = item.category;
        document.getElementById('brand').value = item.brand;
        document.getElementById('unitPrice').value = item.unitPrice;
        document.getElementById('unit').value = item.unit;
        document.getElementById('reorderLevel').value = item.reorderLevel;
        document.getElementById('supplier').value = item.supplier;
        document.getElementById('status').value = item.status;
        itemForm.setAttribute('data-editing-id', id);
        modal.style.display = 'block';
    }
}

// Delete item
function deleteItem(id) {
    if (confirm('Are you sure you want to delete this item?')) {
        itemsData = itemsData.filter(function(i) { return i.id != id; });
        filteredItems = itemsData;
        generateStats();
        generateItemTable();
        alert('Item deleted successfully!');
    }
}

// Filter functions
document.getElementById('categoryFilter').addEventListener('change', applyFilters);
document.getElementById('brandFilter').addEventListener('change', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);

function applyFilters() {
    var categoryFilter = document.getElementById('categoryFilter').value;
    var brandFilter = document.getElementById('brandFilter').value;
    var statusFilter = document.getElementById('statusFilter').value;
    
    filteredItems = itemsData.filter(function(item) {
        var matchCategory = !categoryFilter || item.category === categoryFilter;
        var matchBrand = !brandFilter || item.brand === brandFilter;
        var matchStatus = !statusFilter || item.status === statusFilter;
        return matchCategory && matchBrand && matchStatus;
    });
    
    currentPage = 1;
    generateItemTable();
}

document.getElementById('resetFilter').addEventListener('click', function() {
    document.getElementById('categoryFilter').value = '';
    document.getElementById('brandFilter').value = '';
    document.getElementById('statusFilter').value = '';
    filteredItems = itemsData;
    currentPage = 1;
    generateItemTable();
});

// Search functionality
document.getElementById('searchBtn').addEventListener('click', function() {
    var searchTerm = document.getElementById('searchInput').value.toLowerCase();
    if (searchTerm) {
        filteredItems = itemsData.filter(function(item) {
            return item.name.toLowerCase().includes(searchTerm) ||
                   item.code.toLowerCase().includes(searchTerm) ||
                   item.category.toLowerCase().includes(searchTerm);
        });
        currentPage = 1;
        generateItemTable();
    }
});

document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('searchBtn').click();
    }
});

// Export and Print
document.getElementById('exportBtn').addEventListener('click', function() {
    alert('Exporting items data...');
});

document.getElementById('printBtn').addEventListener('click', function() {
    window.print();
});

// Initialize on page load
window.addEventListener('load', function() {
    generateNav();
    generateHeader();
    generateStats();
    generateItemTable();
    
    setTimeout(function() {
        var navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                // --- THE FIX ---
                // Get the text from the second span, which contains the page name
                var text = this.querySelector('span:last-child').textContent.trim();
                // --- END OF FIX ---
                
                // Set active state for the clicked item
                navItems.forEach(function(nav) { nav.classList.remove('active'); });
                this.classList.add('active');
                
                // Navigate based on the text
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
                    case 'Inventory Management':
                        window.location.href = '../html/inventory_management.html';
                        break;
                    case 'Transaction Management':
                        window.location.href = '../html/transaction_management.html';
                        break;
                    case 'Item Management':
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