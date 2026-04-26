// Menu items data
var menuItems = [
    { name: 'Dashboard', active: false },
    { name: 'User Management', active: false },
    { name: 'Branch Management', active: false },
    { name: 'Warehouse Management', active: true },
    { name: 'Item Management', active: false },
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

// Sample warehouse data
var warehousesData = [
    { id: 1, name: 'Main Warehouse CDO', code: 'WH-CDO-001', branch: 'Main Branch', type: 'Main Warehouse', location: 'Bulua, Cagayan de Oro City', capacity: 500, contact: '0917-123-4567', manager: 'Juan Dela Cruz', openingDate: '2015-01-15', status: 'Active' },
    { id: 2, name: 'CDO Storage Facility', code: 'WH-CDO-002', branch: 'CDO Branch', type: 'Storage Warehouse', location: 'Carmen, Cagayan de Oro City', capacity: 300, contact: '0918-123-4567', manager: 'Maria Santos', openingDate: '2016-03-20', status: 'Active' },
    { id: 3, name: 'Davao Main Warehouse', code: 'WH-DVO-001', branch: 'Davao Branch', type: 'Main Warehouse', location: 'Toril, Davao City', capacity: 600, contact: '0919-123-4567', manager: 'Pedro Reyes', openingDate: '2017-06-10', status: 'Active' },
    { id: 4, name: 'Davao Distribution Center', code: 'WH-DVO-002', branch: 'Davao Branch', type: 'Distribution Center', location: 'Matina, Davao City', capacity: 400, contact: '0920-123-4567', manager: 'Ana Garcia', openingDate: '2018-02-15', status: 'Active' },
    { id: 5, name: 'Tagum Warehouse', code: 'WH-TGM-001', branch: 'Tagum Branch', type: 'Storage Warehouse', location: 'Nueva Fuerza, Tagum City', capacity: 250, contact: '0921-123-4567', manager: 'Jose Ramos', openingDate: '2018-08-05', status: 'Active' },
    { id: 6, name: 'Butuan Storage Hub', code: 'WH-BUT-001', branch: 'Butuan Branch', type: 'Storage Warehouse', location: 'Libertad, Butuan City', capacity: 350, contact: '0922-123-4567', manager: 'Linda Cruz', openingDate: '2019-10-15', status: 'Active' },
    { id: 7, name: 'Pagadian Warehouse', code: 'WH-PAG-001', branch: 'Pagadian Branch', type: 'Storage Warehouse', location: 'San Jose, Pagadian City', capacity: 200, contact: '0923-123-4567', manager: 'Carlos Gomez', openingDate: '2020-02-20', status: 'Inactive' }
];

var currentPage = 1;
var rowsPerPage = 5;
var filteredWarehouses = warehousesData;

// Generate statistics 
function generateStats() {
    var statsContainer = document.getElementById('warehouseStats');
    var totalWarehouses = warehousesData.length;
    var activeWarehouses = warehousesData.filter(function(w) { return w.status === 'Active'; }).length;
    var totalCapacity = warehousesData.reduce(function(sum, w) { return sum + w.capacity; }, 0);
    var mainWarehouses = warehousesData.filter(function(w) { return w.type === 'Main Warehouse'; }).length;
    
    var html = '';
    html += '<div class="stat-card red"><div class="stat-info"><h3>' + totalWarehouses + '</h3><p>Total Warehouses</p></div></div>';
    html += '<div class="stat-card yellow"><div class="stat-info"><h3>' + activeWarehouses + '</h3><p>Active Warehouses</p></div></div>';
    html += '<div class="stat-card red"><div class="stat-info"><h3>' + totalCapacity + '</h3><p>Total Capacity (sq.m.)</p></div></div>';
    html += '<div class="stat-card yellow"><div class="stat-info"><h3>' + mainWarehouses + '</h3><p>Main Warehouses</p></div></div>';
    
    statsContainer.innerHTML = html;
}

// Generate table
function generateWarehouseTable() {
    var tableHead = document.getElementById('tableHead');
    var tableBody = document.getElementById('tableBody');
    
    var headHtml = '<tr>';
    var headers = ['ID', 'Warehouse Name', 'Code', 'Branch', 'Type', 'Capacity', 'Manager', 'Status', 'Actions'];
    for (var i = 0; i < headers.length; i++) {
        headHtml += '<th>' + headers[i] + '</th>';
    }
    headHtml += '</tr>';
    tableHead.innerHTML = headHtml;
    
    var start = (currentPage - 1) * rowsPerPage;
    var end = start + rowsPerPage;
    var paginatedWarehouses = filteredWarehouses.slice(start, end);
    
    var bodyHtml = '';
    for (var i = 0; i < paginatedWarehouses.length; i++) {
        var warehouse = paginatedWarehouses[i];
        var statusClass = warehouse.status === 'Active' ? 'status-active' : 'status-inactive';
        
        bodyHtml += '<tr>';
        bodyHtml += '<td>' + warehouse.id + '</td>';
        bodyHtml += '<td>' + warehouse.name + '</td>';
        bodyHtml += '<td>' + warehouse.code + '</td>';
        bodyHtml += '<td>' + warehouse.branch + '</td>';
        bodyHtml += '<td>' + warehouse.type + '</td>';
        bodyHtml += '<td>' + warehouse.capacity + ' sq.m.</td>';
        bodyHtml += '<td>' + warehouse.manager + '</td>';
        bodyHtml += '<td><span class="' + statusClass + '">' + warehouse.status + '</span></td>';
        bodyHtml += '<td>';
        bodyHtml += '<div class="table-actions">';
        bodyHtml += '<button class="btn-view" data-id="' + warehouse.id + '">View</button>';
        bodyHtml += '<button class="btn-edit" data-id="' + warehouse.id + '">Edit</button>';
        bodyHtml += '<button class="btn-delete" data-id="' + warehouse.id + '">Delete</button>';
        bodyHtml += '</div>';
        bodyHtml += '</td>';
        bodyHtml += '</tr>';
    }
    
    if (paginatedWarehouses.length === 0) {
        bodyHtml = '<tr><td colspan="9" style="text-align: center; color: #999;">No warehouses found</td></tr>';
    }
    
    tableBody.innerHTML = bodyHtml;
    
    addTableEventListeners();
    generatePagination();
}


function addTableEventListeners() {
    var viewBtns = document.querySelectorAll('.btn-view');
    viewBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var warehouse = warehousesData.find(function(w) { return w.id == id; });
            if (warehouse) {
                var info = 'Warehouse Details:\n\n';
                info += 'Name: ' + warehouse.name + '\n';
                info += 'Code: ' + warehouse.code + '\n';
                info += 'Branch: ' + warehouse.branch + '\n';
                info += 'Type: ' + warehouse.type + '\n';
                info += 'Location: ' + warehouse.location + '\n';
                info += 'Capacity: ' + warehouse.capacity + ' sq.m.\n';
                info += 'Contact: ' + warehouse.contact + '\n';
                info += 'Manager: ' + warehouse.manager + '\n';
                info += 'Opening Date: ' + warehouse.openingDate + '\n';
                info += 'Status: ' + warehouse.status;
                alert(info);
            }
        });
    });
    
    var editBtns = document.querySelectorAll('.btn-edit');
    editBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            editWarehouse(id);
        });
    });
    
    var deleteBtns = document.querySelectorAll('.btn-delete');
    deleteBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            deleteWarehouse(id);
        });
    });
}

// Generate pagination
function generatePagination() {
    var pagination = document.getElementById('pagination');
    var totalPages = Math.ceil(filteredWarehouses.length / rowsPerPage);
    
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
            generateWarehouseTable();
        }
    });
    
    document.getElementById('nextPage').addEventListener('click', function() {
        var totalPages = Math.ceil(filteredWarehouses.length / rowsPerPage);
        if (currentPage < totalPages) {
            currentPage++;
            generateWarehouseTable();
        }
    });
    
    var pageBtns = pagination.querySelectorAll('button[data-page]');
    pageBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            currentPage = parseInt(this.getAttribute('data-page'));
            generateWarehouseTable();
        });
    });
}

// Modal functions
var modal = document.getElementById('warehouseModal');
var addWarehouseBtn = document.getElementById('addWarehouseBtn');
var closeModal = document.getElementById('closeModal');
var cancelBtn = document.getElementById('cancelBtn');
var warehouseForm = document.getElementById('warehouseForm');

addWarehouseBtn.addEventListener('click', function() {
    document.getElementById('modalTitle').textContent = 'Add New Warehouse';
    warehouseForm.reset();
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
warehouseForm.addEventListener('submit', function(e) {
    e.preventDefault();
    
    var editingId = warehouseForm.getAttribute('data-editing-id');
    
    if (editingId) {
        // Update existing warehouse
        var index = warehousesData.findIndex(function(w) { return w.id == editingId; });
        if (index !== -1) {
            warehousesData[index] = {
                id: parseInt(editingId),
                name: document.getElementById('warehouseName').value,
                code: document.getElementById('warehouseCode').value,
                branch: document.getElementById('branch').value,
                type: document.getElementById('type').value,
                location: document.getElementById('location').value,
                capacity: parseInt(document.getElementById('capacity').value),
                contact: document.getElementById('contactNumber').value,
                manager: document.getElementById('manager').value,
                openingDate: document.getElementById('openingDate').value,
                status: document.getElementById('status').value
            };
            alert('Warehouse updated successfully!');
        }
        warehouseForm.removeAttribute('data-editing-id');
    } else {
        // Add new warehouse
        var newWarehouse = {
            id: warehousesData.length + 1,
            name: document.getElementById('warehouseName').value,
            code: document.getElementById('warehouseCode').value,
            branch: document.getElementById('branch').value,
            type: document.getElementById('type').value,
            location: document.getElementById('location').value,
            capacity: parseInt(document.getElementById('capacity').value),
            contact: document.getElementById('contactNumber').value,
            manager: document.getElementById('manager').value,
            openingDate: document.getElementById('openingDate').value,
            status: document.getElementById('status').value
        };
        warehousesData.push(newWarehouse);
        alert('Warehouse added successfully!');
    }
    
    filteredWarehouses = warehousesData;
    generateStats();
    generateWarehouseTable();
    modal.style.display = 'none';
});

// Edit warehouse
function editWarehouse(id) {
    var warehouse = warehousesData.find(function(w) { return w.id == id; });
    if (warehouse) {
        document.getElementById('modalTitle').textContent = 'Edit Warehouse';
        document.getElementById('warehouseName').value = warehouse.name;
        document.getElementById('warehouseCode').value = warehouse.code;
        document.getElementById('branch').value = warehouse.branch;
        document.getElementById('type').value = warehouse.type;
        document.getElementById('location').value = warehouse.location;
        document.getElementById('capacity').value = warehouse.capacity;
        document.getElementById('contactNumber').value = warehouse.contact;
        document.getElementById('manager').value = warehouse.manager;
        document.getElementById('openingDate').value = warehouse.openingDate;
        document.getElementById('status').value = warehouse.status;
        warehouseForm.setAttribute('data-editing-id', id);
        modal.style.display = 'block';
    }
}

// Delete warehouse
function deleteWarehouse(id) {
    if (confirm('Are you sure you want to delete this warehouse?')) {
        warehousesData = warehousesData.filter(function(w) { return w.id != id; });
        filteredWarehouses = warehousesData;
        generateStats();
        generateWarehouseTable();
        alert('Warehouse deleted successfully!');
    }
}

// Filter functions
document.getElementById('branchFilter').addEventListener('change', applyFilters);
document.getElementById('typeFilter').addEventListener('change', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);

function applyFilters() {
    var branchFilter = document.getElementById('branchFilter').value;
    var typeFilter = document.getElementById('typeFilter').value;
    var statusFilter = document.getElementById('statusFilter').value;
    
    filteredWarehouses = warehousesData.filter(function(warehouse) {
        var matchBranch = !branchFilter || warehouse.branch === branchFilter;
        var matchType = !typeFilter || warehouse.type === typeFilter;
        var matchStatus = !statusFilter || warehouse.status === statusFilter;
        return matchBranch && matchType && matchStatus;
    });
    
    currentPage = 1;
    generateWarehouseTable();
}

document.getElementById('resetFilter').addEventListener('click', function() {
    document.getElementById('branchFilter').value = '';
    document.getElementById('typeFilter').value = '';
    document.getElementById('statusFilter').value = '';
    filteredWarehouses = warehousesData;
    currentPage = 1;
    generateWarehouseTable();
});

// Search functionality
document.getElementById('searchBtn').addEventListener('click', function() {
    var searchTerm = document.getElementById('searchInput').value.toLowerCase();
    if (searchTerm) {
        filteredWarehouses = warehousesData.filter(function(warehouse) {
            return warehouse.name.toLowerCase().includes(searchTerm) ||
                   warehouse.code.toLowerCase().includes(searchTerm) ||
                   warehouse.branch.toLowerCase().includes(searchTerm);
        });
        currentPage = 1;
        generateWarehouseTable();
    }
});

document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('searchBtn').click();
    }
});

// Export and Print
document.getElementById('exportBtn').addEventListener('click', function() {
    alert('Exporting warehouses data...');
});

document.getElementById('printBtn').addEventListener('click', function() {
    window.print();
});

// Initialize on page load
window.addEventListener('load', function() {
    generateNav();
    generateHeader();
    generateStats();
    generateWarehouseTable();
    
    setTimeout(function() {
        var navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Get the text from the second span, which contains the page name
                var text = this.querySelector('span:last-child').textContent.trim();
                
                // Set active state for the clicked item
                navItems.forEach(function(nav) { nav.classList.remove('active'); });
                this.classList.add('active');
                
                // Navigate based on the text
                switch (text) {
                    case 'Dashboard':
                        window.location.href = '../../frontend/html/dashboard_air.html';
                        break;
                    case 'User Management':
                        window.location.href = '../../frontend/html/user_management.html';
                        break;
                    case 'Branch Management':
                        window.location.href = '../../frontend/html/branch_management.html';
                        break;
                    case 'Warehouse Management':
                        
                        break;
                    case 'Item Management':
                        window.location.href = '../../frontend/html/item_management.html';
                        break;
                     case 'Transaction Management':
                        window.location.href = '../../frontend/html/transaction_management.html';
                        break;
                    case 'Inventory Management':
                        window.location.href = '../../frontend/html/inventory_management.html';
                        break;
                    default:
                        alert('Module "' + text + '" is under development');
                        break;
                }
            });
        });
    }, 100);
});