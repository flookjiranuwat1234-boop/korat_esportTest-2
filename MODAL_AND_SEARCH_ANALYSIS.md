# การวิเคราะห์ Modal & Search ใน manage-teams.php

---

## 1️⃣ MODAL HTML (เพิ่มทีม/ผู้แข่งขัน)

### ตำแหน่ง: Line 1710-1740

```html
<div id="addSoloPlayerModal" data-play-mode="<?= htmlspecialchars((string) $tournament['play_mode'], ENT_QUOTES) ?>" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/70 p-4">
    <div class="w-full max-w-3xl rounded-2xl border border-slate-200 bg-white shadow-2xl overflow-visible">
        <div class="flex items-center justify-between rounded-t-2xl border-b border-slate-200 bg-slate-50 px-6 py-4">
            <div>
                <p class="text-[10px] uppercase tracking-[0.2em] text-slate-500">เพิ่มผู้แข่งขัน</p>
                <h3 id="addPlayerModalTitle" class="text-lg font-black text-slate-900">
                    เพิ่ม<?= ($tournament['play_mode'] ?? 'team') === 'solo' ? 'ผู้แข่งขัน Solo' : 'ทีม' ?>
                </h3>
            </div>
            <button type="button" id="closeAddSoloPlayerModal" class="text-slate-400 hover:text-slate-600 p-1" aria-label="ปิดหน้าต่างเพิ่มผู้แข่งขัน">
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>
        </div>
        <div class="p-6 space-y-5">
            <form id="addPlayerSearchForm" method="GET" class="grid gap-3 md:grid-cols-[1fr_auto]">
                <input type="hidden" name="tournament_id" value="<?= (int) $tournamentId ?>">
                <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
                
                <div class="relative">
                    <label id="addPlayerSearchLabel" class="block text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-1">
                        <?= ($tournament['play_mode'] ?? 'team') === 'solo' ? 'ค้นหาผู้เล่นที่มีบัญชีและโปรไฟล์แล้ว' : 'ค้นหาทีมที่พร้อมสมัคร' ?>
                    </label>
                    <input id="addPlayerSearchInput" type="text" name="add_player_search" value="<?= htmlspecialchars($addPlayerSearch) ?>" 
                        placeholder="พิมพ์ชื่อเพื่อค้นหา..." autocomplete="off" role="combobox" aria-autocomplete="list" 
                        aria-controls="addPlayerSearchResultsList" 
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                    
                    <div id="addPlayerSearchResultsContainer" class="hidden absolute left-0 right-0 top-full z-50 mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                        <div id="addPlayerSearchEmptyState" class="hidden p-4 text-sm text-slate-600">ไม่พบผู้เล่นที่ตรงกับคำค้นหา</div>
                        <div id="addPlayerSearchLoadingState" class="hidden p-4 text-sm text-sky-700">กำลังค้นหา...</div>
                        <div id="addPlayerSearchErrorState" class="hidden p-4 text-sm text-red-700">ไม่สามารถค้นหาข้อมูลได้ กรุณาลองใหม่</div>
                        <div id="addPlayerSearchResultsList" class="max-h-[320px] overflow-y-auto divide-y divide-slate-200"></div>
                    </div>
                </div>

                <div class="flex items-end">
                    <button type="submit" class="rounded-xl bg-brand-orange px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-glow">
                        ค้นหา
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
```

---

## 2️⃣ JAVASCRIPT - Modal Management

### ตำแหน่ง: Line 1831-1889

```javascript
// =============== MODAL CONTROL ===============
const addSoloPlayerModal = document.getElementById('addSoloPlayerModal');
const openAddSoloPlayerModal = document.getElementById('openAddSoloPlayerModal');
const closeAddSoloPlayerModalBtn = document.getElementById('closeAddSoloPlayerModal');

function openAddPlayerModal() {
    if (!addSoloPlayerModal) return;
    if (addPlayerSearchInput) addPlayerSearchInput.value = '';
    addPlayerSearchResultsList?.replaceChildren();
    addPlayerSearchResultsContainer?.classList.add('hidden');
    addPlayerSearchEmptyState?.classList.add('hidden');
    addPlayerSearchLoadingState?.classList.add('hidden');
    addPlayerSearchErrorState?.classList.add('hidden');
    addSoloPlayerModal.classList.remove('hidden');
    addSoloPlayerModal.classList.add('flex');
    addPlayerSearchInput?.focus();
    renderAddPlayerSearchResults(initialAddPlayerSearchResults);
}

function closeAddPlayerModal() {
    if (!addSoloPlayerModal) return;
    addSoloPlayerModal.classList.add('hidden');
    addSoloPlayerModal.classList.remove('flex');
    if (addPlayerSearchInput) addPlayerSearchInput.value = '';
    addPlayerSearchResultsList?.replaceChildren();
    addPlayerSearchResultsContainer?.classList.add('hidden');
    addPlayerSearchEmptyState?.classList.add('hidden');
    addPlayerSearchLoadingState?.classList.add('hidden');
    addPlayerSearchErrorState?.classList.add('hidden');
}

// =============== EVENT LISTENERS ===============
if (openAddSoloPlayerModal) {
    openAddSoloPlayerModal.addEventListener('click', openAddPlayerModal);
}
if (closeAddSoloPlayerModalBtn) {
    closeAddSoloPlayerModalBtn.addEventListener('click', closeAddPlayerModal);
}
if (addSoloPlayerModal) {
    addSoloPlayerModal.addEventListener('click', event => {
        if (event.target === addSoloPlayerModal) closeAddPlayerModal();
    });
}

// =============== KEYBOARD SHORTCUTS ===============
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        closeAddPlayerModal();
    }
});
```

---

## 3️⃣ AJAX SEARCH HANDLER

### ตำแหน่ง: Line 1892-1960

```javascript
// =============== SEARCH RESULTS RENDERING ===============
function renderAddPlayerSearchResults(players) {
    if (!addPlayerSearchResultsList || !addPlayerSearchResultsContainer || !addPlayerSearchEmptyState) return;

    addPlayerSearchResultsList.replaceChildren();
    const isSolo = addSoloPlayerModal?.dataset.playMode === 'solo';
    
    players.forEach(player => {
        const row = document.createElement('div');
        row.className = 'player-search-result flex flex-col md:flex-row md:items-center md:justify-between gap-3 px-4 py-3';

        // Build player details HTML...
        const details = document.createElement('div');
        details.className = 'flex items-center gap-3';
        
        // Avatar/Logo
        const avatar = document.createElement('div');
        avatar.className = 'flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-orange-100 text-sm font-black text-brand-orange';
        
        const imagePath = isSolo ? player.avatar_path : player.logo_path;
        if (imagePath) {
            const image = document.createElement('img');
            image.src = `../${String(imagePath).replace(/^\/+/, '')}`;
            image.className = 'h-full w-full object-cover';
            avatar.append(image);
        } else {
            avatar.textContent = (isSolo ? (player.display_name || player.real_name) : player.name || 'T').trim().charAt(0).toUpperCase();
        }

        // Name & Account Info
        const name = document.createElement('div');
        name.className = 'font-bold text-slate-800';
        name.textContent = isSolo ? (player.display_name || player.real_name || 'Player') : (player.name || 'Team');
        
        const account = document.createElement('div');
        account.className = 'text-[11px] text-slate-500';
        account.textContent = isSolo
            ? `${player.username || '-'} • ${player.email || '-'} • ${player.eligibility_status || 'ไม่ระบุสถานะ'}`
            : `กัปตัน: ${player.captain_username || '-'} • สมาชิก ${player.active_member_count || 0}/${player.starters_count || 0} คน • ${player.status || 'ไม่ระบุสถานะ'}`;

        // ... append to details
    });

    // Show/hide empty states
    const hasResults = players.length > 0;
    addPlayerSearchEmptyState.classList.toggle('hidden', hasResults);
    addPlayerSearchResultsContainer.classList.toggle('hidden', !hasResults);
}

// =============== ASYNC SEARCH FUNCTION ===============
async function updateAddPlayerSearchResults() {
    if (!addPlayerSearchForm || !addPlayerSearchInput || !addPlayerSearchResultsList) return;

    const query = (addPlayerSearchInput.value || '').trim().toLowerCase();
    if (!query) {
        renderAddPlayerSearchResults(initialAddPlayerSearchResults);
        return;
    }

    const requestId = ++addPlayerSearchRequestId;
    const url = new URL(window.location.href);
    url.searchParams.set('ajax', 'search_add_solo_players');
    if (query) url.searchParams.set('add_player_search', query);

    addPlayerSearchLoadingState?.classList.remove('hidden');
    addPlayerSearchErrorState?.classList.add('hidden');
    addPlayerSearchResultsContainer?.classList.remove('hidden');

    try {
        const response = await fetch(url, { 
            headers: { 'X-Requested-With': 'XMLHttpRequest' } 
        });
        if (!response.ok || requestId !== addPlayerSearchRequestId) throw new Error('Search request failed');
        renderAddPlayerSearchResults(await response.json());
    } catch (error) {
        if (requestId === addPlayerSearchRequestId) {
            addPlayerSearchLoadingState?.classList.add('hidden');
            addPlayerSearchErrorState?.classList.remove('hidden');
        }
    }
}

// =============== INPUT EVENT DEBOUNCE ===============
if (addPlayerSearchForm && addPlayerSearchInput) {
    addPlayerSearchForm.addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(addPlayerSearchDebounce);
        updateAddPlayerSearchResults();
    });

    addPlayerSearchInput.addEventListener('input', () => {
        clearTimeout(addPlayerSearchDebounce);
        const query = addPlayerSearchInput.value.trim();
        addPlayerSearchDebounce = setTimeout(updateAddPlayerSearchResults, 400); // 400ms debounce
    });
    
    addPlayerSearchInput.addEventListener('focus', () => {
        if (addPlayerSearchInput.value.trim().length >= 2) updateAddPlayerSearchResults();
    });
}
```

---

## 4️⃣ PHP BACKEND - ดึงรายชื่อทีม/ผู้แข่งขัน

### ตำแหน่ง: Line 207-320

### Solo Mode (ค้นหาผู้เล่น):
```php
$playerSearchSql = "
    SELECT p.player_id, p.display_name, p.real_name, p.avatar_path, p.eligibility_status,
           u.user_id, u.username, u.email, u.status AS account_status,
           (SELECT COUNT(*)
            FROM tournament_registrations tr
            WHERE tr.player_id = p.player_id
              AND tr.tournament_id = :tournament_id
              AND tr.status IN ('pending', 'approved')) AS already_registered_count
    FROM players p
    LEFT JOIN users u ON u.user_id = p.user_id
    WHERE u.status = 'active'
      AND p.user_id IS NOT NULL
";

// ค้นหาโดยฟิลเตอร์
if ($addPlayerSearch !== '') {
    $playerSearchSql .= "
      AND (
          p.display_name LIKE :search OR
          p.real_name LIKE :search OR
          u.username LIKE :search OR
          u.email LIKE :search
      )
    ";
    $playerSearchParams['search'] = '%' . $addPlayerSearch . '%';
}
$playerSearchSql .= ' ORDER BY p.display_name ASC, u.username ASC';
```

### Team Mode (ค้นหาทีม):
```php
$teamSearchSql = "
    SELECT t.team_id, t.name, t.logo_path, t.status, t.game_id,
           COALESCE(cu.username, '-') AS captain_username,
           COUNT(DISTINCT CASE WHEN tm.is_active = 1 THEN tm.player_id END) AS active_member_count,
           tc.starters_count,
           (SELECT COUNT(*)
            FROM tournament_registrations tr
            WHERE tr.team_id = t.team_id
              AND tr.tournament_id = :tournament_id
              AND tr.tournament_category_id = :category_id
              AND tr.status IN ('pending', 'approved')) AS already_registered_count
    FROM teams t
    LEFT JOIN tournament_categories tc ON tc.tournament_category_id = :category_id 
        AND tc.tournament_id = :tournament_id AND tc.is_active = 1
    LEFT JOIN players cp ON cp.player_id = t.captain_player_id
    LEFT JOIN users cu ON cu.user_id = cp.user_id
    LEFT JOIN team_members tm ON tm.team_id = t.team_id
    WHERE (t.game_id = :game_id OR (t.game_id IS NULL AND t.tag LIKE 'F64%'))
      AND t.status = 'active'
      AND (GENDER CHECK: male/female/mixed categories)
";

// ค้นหาโดยฟิลเตอร์
if ($addPlayerSearch !== '') {
    $teamSearchSql .= "
      AND (t.name LIKE :search OR cu.username LIKE :search)
    ";
    $teamSearchParams['search'] = '%' . $addPlayerSearch . '%';
}
$teamSearchSql .= ' GROUP BY t.team_id ORDER BY t.name ASC';
```

### AJAX Response Return (Line 322-326):
```php
if ($isAddPlayerSearchAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($addPlayerSearchResults, JSON_UNESCAPED_UNICODE);
    exit;
}
```

---

## 5️⃣ STATUS DISPLAY & CONTROL

### ตำแหน่ง: Line 1396-1437

```html
<!-- ปุ่มเปิด Modal -->
<button type="button" id="openAddSoloPlayerModal" class="inline-flex items-center gap-2 rounded-xl bg-brand-orange px-4 py-2 text-sm font-bold text-white hover:bg-brand-glow">
    <i class="fa-solid fa-plus"></i> + เพิ่มทีม/ผู้แข่งขัน
</button>

<!-- Action Menu ของแต่ละรายการ -->
<div id="registration-menu-<?= $registrationId ?>" class="admin-action-menu registration-action-menu fixed hidden z-[70] rounded-xl border border-slate-200 bg-white shadow-xl">
    <div class="admin-action-group">การตรวจสอบ</div>
    <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>&registration_action=view_roster" 
       class="admin-action-item text-slate-700 hover:bg-slate-50">
        <i class="fa-solid fa-clipboard-check text-slate-400"></i>ตรวจ Tournament Roster
    </a>
    <!-- ... เมนูอื่นๆ ... -->
</div>
```

---

## 6️⃣ WORKFLOW SEQUENCE (ลำดับการทำงาน)

```
┌─────────────────────────────────────────────────────────┐
│ 1. USER CLICKS BUTTON (Line 1425)                       │
│    openAddSoloPlayerModal → triggers openAddPlayerModal()│
└─────────────────────────┬───────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 2. MODAL OPENS (Line 1543-1570)                         │
│    - Clear search input                                 │
│    - Show modal with flex display                       │
│    - Load initialAddPlayerSearchResults (preloaded)     │
│    - Focus on search input                              │
└─────────────────────────┬───────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 3. RENDER INITIAL RESULTS (Line 1918-1950)              │
│    - Display preloaded data (PHP rendered at load time) │
│    - Show avatar/logo, name, email/members             │
│    - Check eligibility & render buttons                │
└─────────────────────────┬───────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 4. USER TYPES IN SEARCH (Line 1957-1978)                │
│    - Input event triggered                             │
│    - 400ms debounce delay                               │
│    - Show loading state                                │
└─────────────────────────┬───────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 5. AJAX REQUEST (Line 1930-1945)                        │
│    URL params:                                          │
│    ?ajax=search_add_solo_players                        │
│    &add_player_search=<query>                           │
│    &tournament_id=<id>                                  │
│    &category_id=<id>                                    │
│                                                         │
│    Backend (Line 322-326):                              │
│    - Execute Query                                      │
│    - Return JSON with UTF-8                             │
└─────────────────────────┬───────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 6. RENDER RESULTS (Line 1918-1975)                      │
│    - Parse JSON response                                │
│    - Build HTML rows dynamically                        │
│    - Show add buttons or disabled state                 │
│    - Handle empty results                               │
└─────────────────────────┬───────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 7. USER SELECTS PARTICIPANT (Line 1951)                 │
│    - Click "เพิ่มทีม/ผู้แข่งขัน"                         │
│    - Form submits to manage-teams.php                  │
│    - POST action: add_solo_player / add_team            │
└─────────────────────────┬───────────────────────────────┘
                          ↓
┌─────────────────────────────────────────────────────────┐
│ 8. BACKEND PROCESSING (Line 328-435)                    │
│    - Validate CSRF token                                │
│    - Check tournament status                            │
│    - Verify category & participant eligibility          │
│    - Begin transaction                                  │
│    - Insert into tournament_registrations               │
│    - Create roster snapshot                             │
│    - Record status history                              │
│    - Commit transaction                                 │
│    - Show success message & redirect                    │
└─────────────────────────────────────────────────────────┘
```

---

## 📋 Summary Table

| ส่วน | ตำแหน่ง | วัตถุประสงค์ |
|------|--------|-----------|
| **Modal HTML** | 1710-1740 | โครงสร้างหน้าต่างเพิ่มผู้แข่งขัน |
| **Modal Open/Close JS** | 1543-1570, 1831-1889 | ควบคุมการเปิด-ปิด Modal |
| **Search Render** | 1918-1975 | แสดงผลลัพธ์ค้นหา |
| **AJAX Fetch** | 1930-1945 | ส่งคำขอค้นหาไปยัง Backend |
| **Debounce Handler** | 1957-1978 | ควบคุมความถี่ของ AJAX Request |
| **PHP Backend Solo** | 207-250 | ดึงรายชื่อผู้เล่นจากฐานข้อมูล |
| **PHP Backend Team** | 253-320 | ดึงรายชื่อทีมจากฐานข้อมูล |
| **AJAX Response** | 322-326 | ส่ง JSON กลับไปยัง Frontend |
| **Add Handler** | 328-435 | บันทึกการสมัครใหม่ลงฐานข้อมูล |

---

## 🔑 Key Features

### Search Behavior:
- **Preload Initial Data**: PHP preloads data at page load (Line 207-320)
- **Real-time Search**: 400ms debounce input (Line 1960-1978)
- **AJAX with Request ID**: Prevents race conditions (Line 1917, 1932)
- **Dropdown Positioning**: Auto-positions below input (Line 1547-1556)

### Validation:
- **Solo Mode**: Check account status, eligibility, duplicate registration
- **Team Mode**: Check game match, member count, gender eligibility, duplicate registration
- **Category Lock**: Auto-select first category or require manual selection

### Status Display:
- `สมัครแล้ว` - Already registered
- `สมาชิกไม่ครบ` - Insufficient members (team mode)
- `เลือก Category ก่อน` - No category selected

### PHP Preload (Line 306):
```php
const initialAddPlayerSearchResults = <?= json_encode($addPlayerSearchResults, 
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
```

