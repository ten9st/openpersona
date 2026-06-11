import { test, expect } from '@playwright/test'

test('ログインページが表示される', async ({ page }) => {
  await page.goto('/login')
  await expect(page.locator('h1')).toContainText('ログイン')
})

test('ログインできる', async ({ page }) => {
  await page.goto('/login')
  await page.fill('input[type="email"]', 'takeda.ten9@gmail.com')
  await page.fill('input[type="password"]', 'ten91088@')
  
  // type="submit"ではなくテキストでボタンを特定
  await page.click('button:has-text("ログインする")')
  
  await expect(page).toHaveURL('/posts')
})