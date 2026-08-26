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

        // 事件委派：處理受益者清單中 view/assign/delete 按鈕（避免依賴 inline onclick）
        document.body.addEventListener('click', function (event) {
            const btn = event.target.closest && event.target.closest('button[data-action]');
            if (!btn) return;
            const action = btn.dataset.action;
            try {
                if (action === 'view' && typeof openViewBeneficiaryModal === 'function') {
                    openViewBeneficiaryModal(btn);
                    event.preventDefault();
                } else if (action === 'assign' && typeof openAssignBeneficiaryModal === 'function') {
                    openAssignBeneficiaryModal(btn);
                    event.preventDefault();
                } else if (action === 'delete' && typeof confirmDeleteBeneficiary === 'function') {
                    confirmDeleteBeneficiary(btn);
                    event.preventDefault();
                }
            } catch (err) {
                console.error('Beneficiary action handler error:', err);
            }
        });

        // Click diagnostics (capture-phase) — 用於偵錯：顯示被點擊元素是否有動作屬性或可能的處理器
        let clickDiagnosticsEnabled = false;
        function diagnosticListener(ev) {
            const el = ev.target;
            const btn = el.closest ? el.closest('button, a, input, [role="button"]') : null;
            const item = btn || el;
            if (!item) return;

            const info = {
                tag: item.tagName,
                id: item.id || null,
                classes: item.className || null,
                hasOnclickAttr: !!item.getAttribute && item.getAttribute('onclick') ? true : false,
                hasDataAction: !!item.dataset && Object.prototype.hasOwnProperty.call(item.dataset, 'action'),
                isAnchorWithHref: item.tagName === 'A' && !!item.getAttribute('href'),
                isSubmitButton: (item.tagName === 'BUTTON' && (item.type === 'submit' || item.getAttribute('type') === 'submit')) || (item.tagName === 'INPUT' && item.type === 'submit'),
                insideForm: !!item.closest && !!item.closest('form'),
                computedRole: item.getAttribute ? item.getAttribute('role') : null
            };

            console.groupCollapsed('[Click Diagnostics] element clicked');
            console.log(info);
            console.log('DOM element:', item);
            console.groupEnd();

            // 顯示快速 toast 在頁面右上，協助無法看 console 的情況
            const toast = document.createElement('div');
            toast.className = 'diagnostic-toast';
            toast.textContent = `${info.tag}${info.hasDataAction? ' [data-action]' : ''}${info.hasOnclickAttr? ' [onclick]' : ''}${info.isAnchorWithHref? ' [href]' : ''}${info.isSubmitButton? ' [submit]' : ''}`;
            Object.assign(toast.style, {
                position: 'fixed',
                right: '20px',
                top: '80px',
                background: '#111827',
                color: '#fff',
                padding: '8px 12px',
                borderRadius: '6px',
                zIndex: 4000,
                fontSize: '12px',
                boxShadow: '0 6px 18px rgba(0,0,0,0.2)'
            });
            document.body.appendChild(toast);
            setTimeout(function () { toast.remove(); }, 1800);
        }

        // 開放切換診斷器的全域函式
        function enableClickDiagnostics(enable) {
            if (enable && !clickDiagnosticsEnabled) {
                document.addEventListener('click', diagnosticListener, true);
                clickDiagnosticsEnabled = true;
                console.log('Click diagnostics enabled');
            } else if (!enable && clickDiagnosticsEnabled) {
                document.removeEventListener('click', diagnosticListener, true);
                clickDiagnosticsEnabled = false;
                console.log('Click diagnostics disabled');
            }
        }

        window.enableClickDiagnostics = enableClickDiagnostics;
        // 預設開啟偵錯（頁面載入即啟用）
        enableClickDiagnostics(true);

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
            <form method="post" action="?page=inventory">
                <input type="hidden" name="action" value="add_inventory" />
                <div class="form-group">
                    <label>項目名稱*</label>
                    <input type="text" name="item_name" required />
                </div>
                <div class="form-group">
                    <label>分類*</label>
                    <select name="category" required>
                        <option value="">--請選擇--</option>
                        <option value="food">食物</option>
                        <option value="supplies">用品</option>
                        <option value="other">其他</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>初始數量*</label>
                    <input type="number" name="quantity_on_hand" step="0.01" required />
                </div>
                <div class="form-group">
                    <label>單位*</label>
                    <input type="text" name="unit" value="件" required />
                </div>
                <div class="form-group">
                    <label>重訂點</label>
                    <input type="number" name="reorder_level" step="0.01" />
                </div>
                <div class="form-group">
                    <label>保質期</label>
                    <input type="date" name="expiry_date" />
                </div>
                <div class="form-group">
                    <label>位置</label>
                    <input type="text" name="location" />
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
            <form method="post" action="?page=beneficiaries">
                <input type="hidden" name="action" value="add_beneficiary" />
                <div class="form-group">
                    <label>姓名*</label>
                    <div style="display:flex; gap:10px;">
                        <input type="text" name="first_name" placeholder="名字" required style="flex:1;" />
                        <input type="text" name="last_name" placeholder="姓氏" required style="flex:1;" />
                    </div>
                </div>
                <div class="form-group">
                    <label>聯繫電話</label>
                    <input type="tel" name="phone" />
                </div>
                <div class="form-group">
                    <label>郵箱</label>
                    <input type="email" name="email" />
                </div>
                <div class="form-group">
                    <label>地址</label>
                    <textarea name="address"></textarea>
                </div>
                <div class="form-group">
                    <label>家庭成員數*</label>
                    <input type="number" name="family_size" min="1" required />
                </div>
                <div class="form-group">
                    <label>收入級別*</label>
                    <select name="income_level" required>
                        <option value="">--請選擇--</option>
                        <option value="low">低</option>
                        <option value="medium">中</option>
                        <option value="high">高</option>
                    </select>
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

    // 開啟檢視受益者資訊的 modal，接收按鈕元素（包含 data-* 屬性）
    function openViewBeneficiaryModal(btn) {
        const d = btn.dataset;
        const html = `
            <div class="beneficiary-view">
                <p><strong>編號：</strong>${escapeHtml(d.beneficiaryCode || '')}</p>
                <p><strong>姓名：</strong>${escapeHtml(d.firstName || '')} ${escapeHtml(d.lastName || '')}</p>
                <p><strong>電話：</strong>${escapeHtml(d.phone || '')}</p>
                <p><strong>郵箱：</strong>${escapeHtml(d.email || '')}</p>
                <p><strong>地址：</strong>${escapeHtml(d.address || '')}</p>
                <p><strong>家庭成員數：</strong>${escapeHtml(d.familySize || '')}</p>
                <p><strong>收入級別：</strong>${escapeHtml(d.incomeLevel || '')}</p>
                <p><strong>備註：</strong>${escapeHtml(d.notes || '')}</p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">關閉</button>
                </div>
            </div>
        `;
        showModal('受益者資料', html);
    }

    // 開啟分配 modal，包含簡單的表單，會 POST 到 beneficiaries 頁面處理
    function openAssignBeneficiaryModal(btn) {
        const benId = btn.dataset.beneficiaryId;
        const fullName = btn.dataset.fullName || '';
        const html = `
            <form method="post" action="?page=beneficiaries">
                <input type="hidden" name="action" value="assign_beneficiary" />
                <input type="hidden" name="beneficiary_id" value="${escapeHtml(benId)}" />
                <p>為受益者 <strong>${escapeHtml(fullName)}</strong> 建立分配紀錄：</p>
                <div class="form-group">
                    <label>備註（選填）</label>
                    <textarea name="notes"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-primary">建立分配</button>
                </div>
            </form>
        `;
        showModal('受益者分配', html);
    }

    // 小型的 HTML escape 函式
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"'`]/g, function (s) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;","`":"&#x60;"})[s];
        });
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

    // 受益者：檢視、分配、刪除的輔助函式（在 IIFE 中定義，再暴露到 window）
    function openViewBeneficiaryModal(btn) {
        const d = btn ? btn.dataset : {};
        const html = `
            <div class="beneficiary-view">
                <p><strong>編號：</strong>${escapeHtml(d.beneficiaryCode || '')}</p>
                <p><strong>姓名：</strong>${escapeHtml(d.firstName || '')} ${escapeHtml(d.lastName || '')}</p>
                <p><strong>電話：</strong>${escapeHtml(d.phone || '')}</p>
                <p><strong>郵箱：</strong>${escapeHtml(d.email || '')}</p>
                <p><strong>地址：</strong>${escapeHtml(d.address || '')}</p>
                <p><strong>家庭成員數：</strong>${escapeHtml(d.familySize || '')}</p>
                <p><strong>收入級別：</strong>${escapeHtml(d.incomeLevel || '')}</p>
                <p><strong>註冊日期：</strong>${escapeHtml(d.registrationDate || '')}</p>
                <p><strong>備註：</strong>${escapeHtml(d.notes || '')}</p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">關閉</button>
                </div>
            </div>
        `;
        showModal('受益者資料', html);
    }

    function openAssignBeneficiaryModal(btn) {
        const benId = btn ? btn.dataset.beneficiaryId : '';
        const fullName = btn ? (btn.dataset.fullName || '') : '';
        const html = `
            <form method="post" action="?page=beneficiaries">
                <input type="hidden" name="action" value="assign_beneficiary" />
                <input type="hidden" name="beneficiary_id" value="${escapeHtml(benId)}" />
                <p>為受益者 <strong>${escapeHtml(fullName)}</strong> 建立分配紀錄：</p>
                <div class="form-group">
                    <label>備註（選填）</label>
                    <textarea name="notes"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-primary">建立分配</button>
                </div>
            </form>
        `;
        showModal('受益者分配', html);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"'`]/g, function (s) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;","`":"&#x60;"})[s];
        });
    }

    function confirmDeleteBeneficiary(btn) {
        const benId = btn ? btn.dataset.beneficiaryId : 0;
        if (!benId) {
            showNotification('找不到受益者 ID', 'error');
            return;
        }
        if (!confirm('確認要刪除此受益者？此動作無法還原。')) return;

        // 建立隱藏表單並提交，以符合現有後端處理方式
        const form = document.createElement('form');
        form.method = 'post';
        form.action = '?page=beneficiaries';
        form.style.display = 'none';

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_beneficiary';
        form.appendChild(actionInput);

        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'beneficiary_id';
        idInput.value = benId;
        form.appendChild(idInput);

        const csrf = document.querySelector('meta[name="csrf-token"]');
        if (csrf) {
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = csrf.content;
            form.appendChild(csrfInput);
        }

        document.body.appendChild(form);
        form.submit();
    }

    // 將需要被全域呼叫的函式暴露到 window
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

    // 受益者相關全域函式（供 onclick 屬性使用）
    if (typeof openViewBeneficiaryModal === 'function') {
        window.openViewBeneficiaryModal = openViewBeneficiaryModal;
    }
    if (typeof openAssignBeneficiaryModal === 'function') {
        window.openAssignBeneficiaryModal = openAssignBeneficiaryModal;
    }
    if (typeof escapeHtml === 'function') {
        window.escapeHtml = escapeHtml;
    }
    if (typeof confirmDeleteBeneficiary === 'function') {
        window.confirmDeleteBeneficiary = confirmDeleteBeneficiary;
    }
})();
