// frontend/e2e/posts.spec.ts

import { test, expect } from '@playwright/test'
import { requireE2EUser } from './env'

test('投稿一覧が表示される', async ({ page }) => {
  await page.goto('/posts')
  await expect(page.locator('h1')).toContainText('投稿一覧')
})

test('ログインして投稿を作成できる', async ({ page }) => {
  const { email, password } = requireE2EUser()

  // ログイン
  await page.goto('/login')
  await page.fill('input[type="email"]', email)
  await page.fill('input[type="password"]', password)
  await page.click('button:has-text("ログインする")')
  await expect(page).toHaveURL('/posts')

  // 投稿作成
  await page.getByRole('link', { name: '投稿する' }).click()
  await expect(page).toHaveURL('/posts/create')
})