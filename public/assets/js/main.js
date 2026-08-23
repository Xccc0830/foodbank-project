/**
 * Food Bank Admin - Main JS
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initializeEventListeners();
        initializeSidebarToggle();
        injectRuntimeStyles();
    });

    function initializeSidebarToggle() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.querySelector('.sidebar');

        if (!sidebarToggle || !sidebar) {
            return;
        }

        sidebarToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            sidebar.classList.toggle('active');
        });

        document.addEventListener('click', function (event) {
            if (!sidebar.contains(event.target) && !sidebarToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
    }

    function initializeEventListeners() {
        const csrfToken = document.querySelector('meta[name="csrf-token"]');
        if (csrfToken) {
            document.querySelectorAll('form').forEach(function (form) {
                if (!form.querySelector('input[name="csrf_token"]')) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'csrf_token';
                    input.value = csrfToken.content;
                    form.appendChild(input);
                }
            });
        }

        const searchInputs = document.querySelectorAll('.search-input');
        searchInputs.forEach(function (input) {
            input.addEventListener('input', function () {
                handleSearch(input.value);
            });
        });

        const filterSelects = document.querySelectorAll('.filter-select');
        filterSelects.forEach(function (select) {
            select.addEventListener('change', function () {
                handleFilter(select.value);
            });
        });

        updateActiveNavItem();
    }

    function updateActiveNavItem() {
        const currentPage = new URLSearchParams(window.location.search).get('page') || 'dashboard';
        const navItems = document.querySelectorAll('.nav-item');

        navItems.forEach(function (item) {
            const href = item.getAttribute('href') || '';
            item.classList.toggle('active', href.indexOf('page=' + currentPage) !== -1);
        });
    }

    function handleSearch(keyword) {
        const table = document.querySelector('.data-table');
        if (!table) {
            return;
        }

        const rows = table.querySelectorAll('tbody tr');
        const searchText = String(keyword || '').toLowerCase();
        let visibleCount = 0;

        rows.forEach(function (row) {
            const text = row.textContent.toLowerCase();
            const isVisible = text.indexOf(searchText) !== -1;
            row.style.display = isVisible ? '' : 'none';
            if (isVisible) {
                visibleCount += 1;
            }
        });

        if (searchText && visibleCount === 0) {
            showNotification('未找到匹配結果', 'info');
        }
    }

    function handleFilter(filterValue) {
        const table = document.querySelector('.data-table');
        if (!table) {
            return;
        }

        const normalized = String(filterValue || '').toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(function (row) {
            if (!normalized) {
                row.style.display = '';
                return;
            }

            const statusCell = row.querySelector('.status');
            const statusText = statusCell ? statusCell.textContent.toLowerCase() : '';
            row.style.display = statusText.indexOf(normalized) !== -1 ? '' : 'none';
        });
    }

    function switchTab(tabId, event) {
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');

        tabButtons.forEach(function (button) {
            button.classList.remove('active');
        });

        tabContents.forEach(function (content) {
            content.classList.remove('active');
        });

        if (event && event.currentTarget) {
            event.currentTarget.classList.add('active');
        }

        const activeTab = document.getElementById(tabId);
        if (activeTab) {
            activeTab.classList.add('active');
        }
    }

    function openAddDonationModal() {
        showModal('新增捐贈', `
            <form method="post" action="?page=donations" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_donation" />
                <div class="form-group">
                    <label>商家名稱*</label>
                    <input type="text" name="donor_name" required />
                </div>
                <div class="form-group">
                    <label>物資名稱*</label>
                    <input type="text" name="item_name" required />
                </div>
                <div class="form-group">
                    <label>捐贈類型*</label>
                    <select name="donation_type" required>
                        <option value="">--請選擇--</option>
                        <option value="food">食物</option>
                        <option value="supplies">用品</option>
                        <option value="money">現金</option>
                        <option value="other">其他</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>數量*</label>
                    <input type="number" name="quantity" step="0.01" min="0.01" required />
                </div>
                <div class="form-group">
                    <label>單位*</label>
                    <input type="text" name="unit" value="件" required />
                </div>
                <div class="form-group">
                    <label>重量（公斤）</label>
                    <input type="number" name="weight_kg" step="0.01" min="0" />
                </div>
                <div class="form-group">
                    <label>物資大小</label>
                    <input type="text" name="size_description" placeholder="例如：2 箱、中型紙箱" />
                </div>
                <div class="form-group">
                    <label>有效期限</label>
                    <input type="date" name="expiry_date" />
                </div>
                <div class="form-group">
                    <label>最後領取期限</label>
                    <input type="datetime-local" name="pickup_deadline" />
                </div>
                <div class="form-group">
                    <label>配送方式*</label>
                    <select name="delivery_option" required>
                        <option value="volunteer_delivery">志工配送</option>
                        <option value="donor_delivery">商家自行運送</option>
                        <option value="food_bank_pickup">食物銀行派車</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>建議交通工具</label>
                    <select name="vehicle_type">
                        <option value="none">未指定</option>
                        <option value="car">汽車</option>
                        <option value="motorcycle">機車</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>包裝前合照（需附上防拆貼紙）</label>
                    <input type="file" name="photo" accept="image/png,image/jpeg,image/webp" />
                </div>
                <div class="form-group">
                    <label>備註</label>
                    <textarea name="notes"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        `);
    }

    function openAddInventoryModal() {
        showModal('新增庫存項目', `
            <form onsubmit="event.preventDefault(); closeModal(); showNotification('庫存項目已暫存', 'success');">
                <div class="form-group">
                    <label>項目名稱*</label>
                    <input type="text" required />
                </div>
                <div class="form-group">
                    <label>分類*</label>
                    <select required>
                        <option value="">--請選擇--</option>
                        <option value="food">食物</option>
                        <option value="supplies">用品</option>
                        <option value="other">其他</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>初始數量*</label>
                    <input type="number" step="0.01" required />
                </div>
                <div class="form-group">
                    <label>單位*</label>
                    <input type="text" value="件" required />
                </div>
                <div class="form-group">
                    <label>重訂點</label>
                    <input type="number" step="0.01" />
                </div>
                <div class="form-group">
                    <label>保質期</label>
                    <input type="date" />
                </div>
                <div class="form-group">
                    <label>位置</label>
                    <input type="text" />
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        `);
    }

    function openAddBeneficiaryModal() {
        showModal('新增受益者', `
            <form onsubmit="event.preventDefault(); closeModal(); showNotification('受益者資料已暫存', 'success');">
                <div class="form-group">
                    <label>姓名*</label>
                    <div style="display:flex; gap:10px;">
                        <input type="text" placeholder="名字" required style="flex:1;" />
                        <input type="text" placeholder="姓氏" required style="flex:1;" />
                    </div>
                </div>
                <div class="form-group">
                    <label>聯繫電話</label>
                    <input type="tel" />
                </div>
                <div class="form-group">
                    <label>郵箱</label>
                    <input type="email" />
                </div>
                <div class="form-group">
                    <label>地址</label>
                    <textarea></textarea>
                </div>
                <div class="form-group">
                    <label>家庭成員數*</label>
                    <input type="number" min="1" required />
                </div>
                <div class="form-group">
                    <label>收入級別*</label>
                    <select required>
                        <option value="">--請選擇--</option>
                        <option value="low">低</option>
                        <option value="medium">中</option>
                        <option value="high">高</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        `);
    }

    function openNewPurchaseModal() {
        showModal('新增採購單', `
            <form onsubmit="event.preventDefault(); closeModal(); showNotification('採購單已暫存', 'success');">
                <div class="form-group">
                    <label>供應商*</label>
                    <select required>
                        <option value="">--請選擇--</option>
                        <option value="supplier1">ABC 食品供應公司</option>
                        <option value="supplier2">XYZ 商貿公司</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>採購日期*</label>
                    <input type="date" required />
                </div>
                <div class="form-group">
                    <label>預計交貨日期</label>
                    <input type="date" />
                </div>
                <div class="form-group">
                    <label>備註</label>
                    <textarea></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        `);
    }

    function openAddSupplierModal() {
        showModal('新增供應商', `
            <form onsubmit="event.preventDefault(); closeModal(); showNotification('供應商資料已暫存', 'success');">
                <div class="form-group">
                    <label>供應商名稱*</label>
                    <input type="text" required />
                </div>
                <div class="form-group">
                    <label>聯繫人</label>
                    <input type="text" />
                </div>
                <div class="form-group">
                    <label>電話</label>
                    <input type="tel" />
                </div>
                <div class="form-group">
                    <label>郵箱</label>
                    <input type="email" />
                </div>
                <div class="form-group">
                    <label>城市</label>
                    <input type="text" />
                </div>
                <div class="form-group">
                    <label>地址</label>
                    <textarea></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        `);
    }

    function showModal(title, content) {
        const oldModal = document.getElementById('app-modal');
        if (oldModal) {
            oldModal.remove();
        }

        const modalHTML = `
            <div id="app-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-label="${title}">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>${title}</h3>
                        <button type="button" class="modal-close" onclick="closeModal()" aria-label="Close">×</button>
                    </div>
                    <div class="modal-body">${content}</div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        const modal = document.getElementById('app-modal');
        if (!modal) {
            return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]');
        if (csrfToken) {
            modal.querySelectorAll('form').forEach(function (form) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'csrf_token';
                input.value = csrfToken.content;
                form.appendChild(input);
            });
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        const onEsc = function (event) {
            if (event.key === 'Escape') {
                closeModal();
                document.removeEventListener('keydown', onEsc);
            }
        };
        document.addEventListener('keydown', onEsc);
    }

    function closeModal() {
        const modal = document.getElementById('app-modal');
        if (modal) {
            modal.remove();
        }
    }

    function showNotification(message, type) {
        const notificationType = type || 'info';
        const notification = document.createElement('div');
        notification.className = 'notification notification-' + notificationType;
        notification.textContent = message;

        notification.style.cssText = [
            'position:fixed',
            'top:20px',
            'right:20px',
            'padding:12px 16px',
            'border-radius:8px',
            'z-index:3000',
            'font-size:13px',
            'font-weight:600',
            'box-shadow:0 10px 24px rgba(0,0,0,0.15)',
            'animation:slideInToast .2s ease-out'
        ].join(';');

        if (notificationType === 'success') {
            notification.style.backgroundColor = '#10B981';
            notification.style.color = '#FFFFFF';
        } else if (notificationType === 'error') {
            notification.style.backgroundColor = '#EF4444';
            notification.style.color = '#FFFFFF';
        } else if (notificationType === 'warning') {
            notification.style.backgroundColor = '#F59E0B';
            notification.style.color = '#111827';
        } else {
            notification.style.backgroundColor = '#3B82F6';
            notification.style.color = '#FFFFFF';
        }

        document.body.appendChild(notification);

        setTimeout(function () {
            notification.style.animation = 'slideOutToast .2s ease-in';
            setTimeout(function () {
                notification.remove();
            }, 200);
        }, 2500);
    }

    function exportToCSV(filename) {
        const table = document.querySelector('.data-table');
        if (!table) {
            showNotification('找不到可匯出的表格', 'error');
            return;
        }

        const rows = [];
        const headers = [];

        table.querySelectorAll('thead th').forEach(function (th) {
            headers.push(th.textContent.trim());
        });
        rows.push(headers.join(','));

        table.querySelectorAll('tbody tr').forEach(function (tr) {
            if (tr.style.display === 'none') {
                return;
            }
            const cells = [];
            tr.querySelectorAll('td').forEach(function (td) {
                cells.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
            });
            rows.push(cells.join(','));
        });

        const blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename || 'export.csv';
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        showNotification('CSV 匯出完成', 'success');
    }

    function printTable() {
        window.print();
    }

    function injectRuntimeStyles() {
        if (document.getElementById('runtime-ui-styles')) {
            return;
        }

        const styleTag = document.createElement('style');
        styleTag.id = 'runtime-ui-styles';
        styleTag.textContent = `
            .modal-overlay {
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.45);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 2200;
                padding: 16px;
            }

            .modal-content {
                width: min(560px, 100%);
                background: #fff;
                border-radius: 12px;
                border: 1px solid #E5E7EB;
                box-shadow: 0 24px 48px rgba(0, 0, 0, 0.2);
                overflow: hidden;
            }

            .modal-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 18px;
                border-bottom: 1px solid #E5E7EB;
                background: #F9FAFB;
            }

            .modal-header h3 {
                margin: 0;
                font-size: 16px;
                font-weight: 700;
            }

            .modal-close {
                border: 0;
                background: transparent;
                font-size: 24px;
                line-height: 1;
                cursor: pointer;
                color: #6B7280;
            }

            .modal-close:hover {
                color: #111827;
            }

            .modal-body {
                padding: 16px 18px 18px;
                max-height: 75vh;
                overflow-y: auto;
            }

            .modal-actions {
                display: flex;
                justify-content: flex-end;
                gap: 10px;
                margin-top: 12px;
            }

            @keyframes slideInToast {
                from { transform: translateY(-8px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }

            @keyframes slideOutToast {
                from { transform: translateY(0); opacity: 1; }
                to { transform: translateY(-8px); opacity: 0; }
            }
        `;

        document.head.appendChild(styleTag);
    }

    window.openAddDonationModal = openAddDonationModal;
    window.openAddInventoryModal = openAddInventoryModal;
    window.openAddBeneficiaryModal = openAddBeneficiaryModal;
    window.openNewPurchaseModal = openNewPurchaseModal;
    window.openAddSupplierModal = openAddSupplierModal;
    window.closeModal = closeModal;
    window.switchTab = switchTab;
    window.exportToCSV = exportToCSV;
    window.printTable = printTable;
    window.showNotification = showNotification;
})();
