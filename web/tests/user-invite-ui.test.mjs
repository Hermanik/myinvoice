import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const read = (path) => readFile(new URL(path, root), 'utf8')
const locales = async () => {
  const [cs, en] = await Promise.all(['i18n/cs.json', 'i18n/en.json'].map(async p => JSON.parse(await read(p))))
  return { cs, en }
}
const placeholders = (s) => [...s.matchAll(/\{(\w+)\}/g)].map(m => m[1]).sort()

test('the invitation page is a public route', async () => {
  const router = await read('router/index.ts')
  assert.match(router, /path: '\/invite',\s+name: 'invite',[^\n]*InviteAccept\.vue[^\n]*meta: \{ public: true \}/)
})

test('cs and en carry identical, non-empty user_invite keys with matching placeholders', async () => {
  const { cs, en } = await locales()
  assert.deepEqual(Object.keys(cs.user_invite).sort(), Object.keys(en.user_invite).sort())
  for (const [key, value] of Object.entries(cs.user_invite)) {
    assert.ok(value.trim() && en.user_invite[key].trim(), key)
    assert.deepEqual(placeholders(value), placeholders(en.user_invite[key]), key)
    // A literal pipe or stray brace would be parsed by vue-i18n as plural / interpolation syntax.
    assert.doesNotMatch(value.replace(/\{\w+\}/g, ''), /[{}|@]/, key)
    assert.doesNotMatch(en.user_invite[key].replace(/\{\w+\}/g, ''), /[{}|@]/, key)
  }
  assert.ok(cs.users.et_known_codes.user_invite && en.users.et_known_codes.user_invite)
})

test('every user_invite key used by the UI exists', async () => {
  const { cs } = await locales()
  for (const file of ['pages/InviteAccept.vue', 'pages/admin/Users.vue', 'pages/ApiTokens.vue']) {
    const src = await read(file)
    const used = [...src.matchAll(/user_invite\.(\w+)/g)].map(m => m[1])
    assert.ok(used.length > 0, file)
    for (const key of used) assert.ok(cs.user_invite[key], `${file}: user_invite.${key}`)
  }
})

test('a dead link shows the agreed friendly message', async () => {
  const { cs } = await locales()
  assert.equal(cs.user_invite.dead_link, 'Odkaz už neplatí, požádejte správce o nový.')
  const page = await read('pages/InviteAccept.vue')
  assert.match(page, /data-testid="invite-dead"[\s\S]*user_invite\.dead_link/)
  // Greeting and password rules come from the server's answer.
  assert.match(page, /user_invite\.welcome', \{ name: info\?\.name \}/)
  assert.match(page, /user_invite\.password_rules', \{ min: minLength, max: maxLength \}/)
})

test('the invitation page signs the user in through the store and honours forced MFA setup', async () => {
  const page = await read('pages/InviteAccept.vue')
  assert.match(page, /auth\.acceptInvite\(token\.value, password\.value, passwordConfirm\.value\)/)
  assert.match(page, /auth\.mustSetupMfa \|\| auth\.mustSetupTotp\) \? '\/setup-mfa' : '\/'/)
  assert.match(page, /router\.replace\('\/login'\)/)
  const store = await read('stores/auth.ts')
  assert.match(store, /async function acceptInvite/)
  assert.match(store, /login,\s+acceptInvite,/)
})

test('the user form offers both second-factor options, pinned to the default for an admin', async () => {
  const users = await read('pages/admin/Users.vue')
  assert.match(users, /<option value="default">\{\{ t\('user_invite\.mfa_default'\) \}\}<\/option>/)
  assert.match(users, /<option value="email"[^>]*>\{\{ t\('user_invite\.mfa_email'\) \}\}<\/option>/)
  assert.match(users, /:disabled="form\.role === 'admin'"/)
  assert.match(users, /if \(role === 'admin'\) form\.mfa_mode = 'default'/)
  // Inviting a non-admin preselects the emailed code.
  assert.match(users, /role: 'readonly', locale: 'cs', is_active: true, password: '', mfa_mode: 'email'/)
  // Create works without a password and then opens the invitation dialog.
  assert.match(users, /password: form\.password \|\| undefined/)
  assert.match(users, /if \(!form\.password\) openInvite\(created\)/)
})

test('the invitation dialog offers e-mail and copy-link for new and existing users', async () => {
  const users = await read('pages/admin/Users.vue')
  assert.match(users, /issueInvite\(true\)[\s\S]*user_invite\.send_email/)
  assert.match(users, /issueInvite\(false\)[\s\S]*user_invite\.copy_link/)
  assert.match(users, /navigator\.clipboard\.writeText\(result\.invite_link\)/)
  // Row action for every active user = re-invite of an existing account.
  assert.match(users, /v-if="u\.is_active" @click="openInvite\(u\)"/)
  const admin = await read('api/admin.ts')
  assert.match(admin, /post<UserInviteResult>\(`\/admin\/users\/\$\{id\}\/invite`, \{ send_email: sendEmail \}\)/)
})

test('API token creation asks an email-mode user for an emailed code instead of a passkey/TOTP', async () => {
  const tokens = await read('pages/ApiTokens.vue')
  assert.match(tokens, /needsEmailStepUp = computed\(\(\) => auth\.user\?\.mfa_mode === 'email' && !needsStepUp\.value\)/)
  assert.match(tokens, /authApi\.emailStepUpRequest\('api_token\.create'/)
  assert.match(tokens, /authApi\.emailStepUpVerify\('api_token\.create'/)
  assert.match(tokens, /const proof = passkeyStepUpToken\.value \|\| emailStepUpToken\.value/)
})
