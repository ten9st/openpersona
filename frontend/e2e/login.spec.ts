import { test, expect } from '@playwright/test'
import { requireE2EUser } from './env'

test('ログインページが表示される', async ({ page }) => {
  await page.goto('/login')
  await expect(page.locator('h1')).toContainText('ログイン')
})

test('ログインできる', async ({ page }) => {
  const { email, password } = requireE2EUser()

  await page.goto('/login')
  await page.fill('input[type="email"]', email)
  await page.fill('input[type="password"]', password)

  // type="submit"ではなくテキストでボタンを特定
  await page.click('button:has-text("ログインする")')

  await expect(page).toHaveURL('/posts')
})