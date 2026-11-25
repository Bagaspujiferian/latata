import { test, expect } from '@playwright/test';

/**
 * Switch active session using the built-in 1-Click Demo Account Switcher widget.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} email
 */
async function switchDemoAccount(page, email) {
    await page.goto('/');

    const switcherContainer = page.locator('div.fixed.bottom-4.right-4');
    await expect(switcherContainer).toBeVisible();

    const targetFormButton = switcherContainer.locator(
        `form[action*="/demo/switch-user"]:has(input[name="email"][value="${email}"]) button[type="submit"]`
    );

    if (!(await targetFormButton.isVisible())) {
        await switcherContainer.locator('> button').click();
        await expect(targetFormButton).toBeVisible();
    }

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        targetFormButton.click(),
    ]);
}

test.describe('NexaTrade Enterprise E2E Core Flows (Chromium)', () => {
    /** @type {string[]} */
    let pageErrors = [];
    /** @type {string[]} */
    let serverErrors = [];

    test.beforeEach(async ({ page }) => {
        pageErrors = [];
        serverErrors = [];

        page.on('pageerror', (err) => {
            pageErrors.push(err.message);
        });

        page.on('response', (response) => {
            if (response.status() >= 500) {
                serverErrors.push(`${response.status()} ${response.url()}`);
            }
        });
    });

    test.afterEach(async () => {
        expect(pageErrors, `Uncaught pageerror detected: ${pageErrors.join(' | ')}`).toEqual([]);
        expect(serverErrors, `HTTP 500+ response detected: ${serverErrors.join(' | ')}`).toEqual([]);
    });

    test('Flow 1 — Marketplace FULLTEXT & Short-Keyword Search + Product Detail Page', async ({ page }) => {
        await page.goto('/');

        await expect(page.locator('h1')).toContainText('Transaksi Dijamin Escrow');

        // 1. Test MySQL InnoDB FULLTEXT Search (>= 3 characters)
        const desktopSearchInput = page.locator('header form input[name="q"]').first();
        const desktopSearchButton = page.locator('header form button[type="submit"]').first();

        await desktopSearchInput.fill('Kuronami Vandal');
        await Promise.all([
            page.waitForURL(/q=Kuronami/i),
            desktopSearchButton.click(),
        ]);

        await expect(page.locator('body')).toContainText('Akun Valorant Immortal 2');

        // 2. Test Short-Keyword Search (< 3 characters -> escaped LIKE fallback)
        await desktopSearchInput.fill('ML');
        await Promise.all([
            page.waitForURL(/q=ML/i),
            desktopSearchButton.click(),
        ]);

        await expect(page.locator('body')).toContainText('MLBB');

        // 3. Click product card to open Product Detail Page
        const firstProductLink = page.locator('a[href*="/p/"]').first();
        await expect(firstProductLink).toBeVisible();

        await Promise.all([
            page.waitForURL(/\/p\//),
            firstProductLink.click(),
        ]);

        await expect(page.locator('h1').first()).toBeVisible();
        await expect(page.getByRole('heading', { name: /Atur Jumlah & Checkout/i })).toBeVisible();
        await expect(page.locator('button[type="submit"]', { hasText: 'Beli Instan' })).toBeVisible();
        await expect(page.locator('button[type="submit"]', { hasText: 'Tambah ke Keranjang' })).toBeVisible();

        // 4. Verify Alpine.js tab switching on Product Detail Page
        const reviewsTabButton = page.locator('button', { hasText: /Ulasan Pembeli/i });
        await reviewsTabButton.click();
        await expect(reviewsTabButton).toHaveClass(/text-\[#0068E1\]/);
    });

    test('Flow 2 — Seller Bulk Vault Drawer (Alpine.js) & Vacation Mode / SLA-Aware Settings UI', async ({ page }) => {
        await switchDemoAccount(page, 'juragan@nexatrade.id');

        // 1. Open Seller Products & AES-256 Vault Studio
        await page.goto('/seller/products');
        await expect(page.locator('h1')).toContainText('Produk, Galeri Multi-Foto & Brankas AES-256');

        // Click "Brankas" button on the first instant_vault product to toggle Alpine.js vaultOpen drawer
        const vaultToggleButton = page.getByRole('button', { name: 'Brankas', exact: true }).first();
        await expect(vaultToggleButton).toBeVisible();
        await vaultToggleButton.click();

        const bulkVaultTextarea = page.locator('textarea[name="vault_codes_text"]').first();
        const bulkVaultFileInput = page.locator('input[type="file"][name="vault_codes_file"]').first();
        const bulkVaultSubmitBtn = page.locator('button[type="submit"]', { hasText: /Enkripsi & Simpan ke Brankas/i }).first();

        await expect(bulkVaultTextarea).toBeVisible();
        await expect(bulkVaultFileInput).toBeVisible();
        await expect(bulkVaultSubmitBtn).toBeVisible();

        await bulkVaultTextarea.fill('STEAM-E2E-PREVIEW-0001\nSTEAM-E2E-PREVIEW-0002');
        await expect(bulkVaultTextarea).toHaveValue('STEAM-E2E-PREVIEW-0001\nSTEAM-E2E-PREVIEW-0002');

        // 2. Open Seller Settings & verify Vacation Mode + SLA-Aware Operating Hours toggles
        await page.goto('/seller/settings');
        await expect(page.locator('h1')).toContainText('Profil Etalase, Jam Operasional & Ulasan');

        const vacationCheckbox = page.locator('input[type="checkbox"][name="is_on_vacation"]');
        const slaAwareCheckbox = page.locator('input[type="checkbox"][name="operating_hours_enabled"]');
        const vacationNoteInput = page.locator('input[name="vacation_note"]');
        const timezoneSelect = page.locator('select[name="operating_timezone"]');

        await expect(vacationCheckbox).toBeVisible();
        await expect(slaAwareCheckbox).toBeVisible();
        await expect(vacationNoteInput).toBeVisible();
        await expect(timezoneSelect).toBeVisible();

        // Toggle checkboxes in DOM and restore state to verify interactive responsiveness
        const initialVacation = await vacationCheckbox.isChecked();
        await vacationCheckbox.setChecked(!initialVacation);
        expect(await vacationCheckbox.isChecked()).toBe(!initialVacation);
        await vacationCheckbox.setChecked(initialVacation);

        const initialSla = await slaAwareCheckbox.isChecked();
        await slaAwareCheckbox.setChecked(!initialSla);
        expect(await slaAwareCheckbox.isChecked()).toBe(!initialSla);
        await slaAwareCheckbox.setChecked(initialSla);
    });

    test('Flow 3 — Manual Trade Checkout & Buyer Pre-Processing Cancel + Instant Escrow Refund', async ({ page }) => {
        await switchDemoAccount(page, 'bagas@nexatrade.id');

        // 1. Open manual_trade product (5 Blue Gem Locks Growtopia)
        await page.goto('/p/5-blue-gem-locks-growtopia-drop-kilat');
        await expect(page.locator('h1')).toContainText('5 Blue Gem Locks (BGL) Growtopia');

        // Fill required dynamic game inputs
        await page.locator('input[name="game_inputs[grow_id]"]').fill('BagasE2E99');
        await page.locator('input[name="game_inputs[world_name]"]').fill('BAGASE2EWORLD');

        // Click "Beli Instan" (wallet_full default)
        const instantBuyBtn = page.locator('button[type="submit"]', { hasText: 'Beli Instan' });
        await Promise.all([
            page.waitForURL(/\/buyer\/orders\/NXT-/),
            instantBuyBtn.click(),
        ]);

        // 2. Verify order is in PROCESSING state and Pre-Processing Cancel box is visible
        await expect(page.locator('body')).toContainText('PROCESSING');
        await expect(page.locator('body')).toContainText('Batalkan Pesanan (Sebelum Diproses Penjual)');

        const cancelForm = page.locator('form[action$="/cancel"]');
        await expect(cancelForm).toBeVisible();

        const reasonInput = cancelForm.locator('input[name="reason"]');
        const cancelSubmitBtn = cancelForm.locator('button[type="submit"]', { hasText: 'Batalkan Pesanan' });

        await reasonInput.fill('Salah pilih nama world saat pengujian E2E Playwright');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            cancelSubmitBtn.click(),
        ]);

        // 3. Verify order transitioned to REFUNDED and cancel form is gone (wallet & stock restored to exact baseline)
        await expect(page.locator('body')).toContainText('REFUNDED');
        await expect(page.locator('form[action$="/cancel"]')).toHaveCount(0);
    });

    test('Flow 4 — Admin Finance P&L, MDR Cost, BALANCED Ledger & Scheduler Heartbeat Dashboard', async ({ page }) => {
        await switchDemoAccount(page, 'admin@nexatrade.id');

        await page.goto('/admin/finance');
        await expect(page.locator('h1')).toContainText('Keuangan Perusahaan (P&L) & Audit Ledger Global');

        // 1. Verify P&L and MDR KPI cards
        await expect(page.locator('body')).toContainText('GMV (Gross Merchandise Value)');
        await expect(page.locator('body')).toContainText('Pendapatan Kotor Platform');
        await expect(page.locator('body')).toContainText('Biaya MDR Payment Gateway');
        await expect(page.locator('body')).toContainText('True Net Operating Profit');

        // 2. Verify Global Ledger Reconciliation status is BALANCED
        await expect(page.locator('body')).toContainText('BALANCED (Selisih Rp 0)');

        // 3. Verify System Worker Health (Scheduler Heartbeat) table renders all 5 registered workers
        await expect(page.locator('body')).toContainText('System Worker Health (Scheduler Heartbeat)');
        await expect(page.locator('body')).toContainText('nexatrade:sweep-expired');
        await expect(page.locator('body')).toContainText('nexatrade:settle-completed');
        await expect(page.locator('body')).toContainText('nexatrade:reconcile-h2h');
        await expect(page.locator('body')).toContainText('nexatrade:expire-boosts');
        await expect(page.locator('body')).toContainText('nexatrade:audit-ledger');
    });
});
