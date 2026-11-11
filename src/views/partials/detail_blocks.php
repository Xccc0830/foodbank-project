<?php
/**
 * 共用的詳情顯示元件：灰底資訊格（小標題＋內容），以及一項物資一張的物資卡片。
 * 樣式在 public/assets/css/style.css 的「詳情資訊格／物資卡片」區塊。
 */

if (!function_exists('detailNumber')) {
    /** 去掉多餘的零：10.00 → 10、12.50 → 12.5 */
    function detailNumber($value) {
        $text = trim((string) $value);
        return is_numeric($text) && strpos($text, '.') !== false ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * 資訊格。每列為 ['label' => 標題, 'value' => 純文字] 或 ['label' => 標題, 'html' => 已處理過的 HTML]；
     * 加上 'wide' => true 會占兩格（適合地址、備註）。
     *
     * @param string $modifier 額外的 class，例如 detail-info-grid-compact（表格內用的精簡版）
     */
    function renderDetailInfoGrid(array $rows, $modifier = '') {
        if (!$rows) {
            return;
        }
        echo '<dl class="detail-info-grid' . ($modifier !== '' ? ' ' . htmlspecialchars($modifier) : '') . '">';
        foreach ($rows as $row) {
            $content = array_key_exists('html', $row) ? $row['html'] : nl2br(htmlspecialchars((string) ($row['value'] ?? '')));
            echo '<div' . (!empty($row['wide']) ? ' class="detail-info-wide"' : '') . '>'
                . '<dt>' . htmlspecialchars((string) $row['label']) . '</dt>'
                . '<dd>' . $content . '</dd></div>';
        }
        echo '</dl>';
    }

    /** 物資卡片：編號、名稱、分類與保存方式標籤，下方列出數量、重量、期限、包裝、長寬高。 */
    function renderDonationItemCards(array $items, $title = '物資明細') {
        if (!$items) {
            return;
        }
        $storageLabels = ['room' => '常溫', 'chilled' => '冷藏', 'frozen' => '冷凍'];
        ?>
        <div class="detail-items">
            <?php if ($title !== ''): ?>
                <h3 class="detail-section-title"><?php echo htmlspecialchars($title); ?> <span>共 <?php echo count($items); ?> 項</span></h3>
            <?php endif; ?>
            <?php foreach (array_values($items) as $index => $item):
                $dims = array_filter([$item['length_cm'] ?? null, $item['width_cm'] ?? null, $item['height_cm'] ?? null], static function ($v) {
                    return $v !== null && $v !== '';
                });
                $storage = in_array($item['storage_condition'] ?? '', ['room', 'chilled', 'frozen'], true) ? $item['storage_condition'] : 'room';
                $facts = [
                    '數量' => detailNumber($item['quantity'] ?? '') . ' ' . ($item['unit'] ?? ''),
                    '重量' => isset($item['item_weight_kg']) && $item['item_weight_kg'] !== '' ? detailNumber($item['item_weight_kg']) . ' kg' : '未填寫',
                    '保存期限' => $item['expiry_date'] ?? '未填寫',
                    '包裝' => ($item['package_state'] ?? 'sealed') === 'opened' ? '已拆封／分裝' : '原包裝未拆封',
                    '長寬高' => count($dims) === 3 ? implode(' × ', array_map('detailNumber', $dims)) . ' cm' : '未填寫',
                ];
            ?>
                <div class="detail-item-card">
                    <div class="detail-item-head">
                        <span class="detail-item-index"><?php echo $index + 1; ?></span>
                        <strong><?php echo htmlspecialchars($item['item_name'] ?? ''); ?></strong>
                        <?php if (!empty($item['category_name'])): ?>
                            <span class="detail-tag"><?php echo htmlspecialchars($item['category_name']); ?></span>
                        <?php endif; ?>
                        <span class="detail-tag detail-tag-<?php echo $storage; ?>"><?php echo $storageLabels[$storage]; ?></span>
                    </div>
                    <dl class="detail-item-facts">
                        <?php foreach ($facts as $label => $value): ?>
                            <div><dt><?php echo $label; ?></dt><dd><?php echo htmlspecialchars($value); ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /** 物資照片區塊 */
    function renderDonationPhotos($photoPathText, $title = '物資照片') {
        $paths = array_filter(array_map('trim', preg_split('/\s*,\s*/', (string) $photoPathText)));
        echo '<div class="detail-photos"><h3 class="detail-section-title">' . htmlspecialchars($title) . '</h3>';
        if ($paths) {
            echo '<div class="detail-photo-list">';
            foreach ($paths as $path) {
                echo '<img src="' . htmlspecialchars(storedPublicImageUrl($path)) . '" alt="物資照片">';
            }
            echo '</div>';
        } else {
            echo '<p class="text-muted">商家未上傳照片</p>';
        }
        echo '</div>';
    }
}
