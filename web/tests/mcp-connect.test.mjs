import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const { MCP_TOKEN_PLACEHOLDER, claudeCodeCommand, claudeDesktopConfig, mcpEndpoint } =
  await import('../src/utils/mcpConnect.ts')

const root = new URL('../src/', import.meta.url)

test('endpoint is the origin plus /api/mcp', () => {
  assert.equal(mcpEndpoint('https://faktury.example.cz'), 'https://faktury.example.cz/api/mcp')
  assert.equal(mcpEndpoint('http://localhost:8080'), 'http://localhost:8080/api/mcp')
})

test('Claude Code command registers an HTTP server with a bearer header', () => {
  const command = claudeCodeCommand('https://faktury.example.cz/api/mcp')

  assert.equal(
    command,
    `claude mcp add --transport http myinvoice https://faktury.example.cz/api/mcp --header "Authorization: Bearer ${MCP_TOKEN_PLACEHOLDER}"`,
  )
})

test('Claude Desktop snippet is valid JSON that bridges the endpoint through mcp-remote', () => {
  const config = JSON.parse(claudeDesktopConfig('https://faktury.example.cz/api/mcp'))
  const server = config.mcpServers.myinvoice

  assert.equal(server.command, 'npx')
  assert.deepEqual(server.args.slice(0, 3), ['-y', 'mcp-remote', 'https://faktury.example.cz/api/mcp'])
  // No space after the colon: spaces inside args are mangled on Windows, the value comes from env.
  assert.ok(server.args.includes('Authorization:${MYINVOICE_AUTH}'))
  assert.equal(server.env.MYINVOICE_AUTH, `Bearer ${MCP_TOKEN_PLACEHOLDER}`)
})

test('snippets never contain a real-looking secret', () => {
  for (const text of [claudeCodeCommand('https://x/api/mcp'), claudeDesktopConfig('https://x/api/mcp')]) {
    const tokens = text.match(/mi_pat_\S+/g) ?? []
    assert.ok(tokens.length > 0)
    for (const token of tokens) assert.match(token, /^mi_pat_X+/)
  }
})

test('every MCP help text used by the token page exists in both locales', async () => {
  const page = await readFile(new URL('pages/ApiTokens.vue', root), 'utf8')
  const cs = JSON.parse(await readFile(new URL('i18n/cs.json', root), 'utf8')).api_tokens
  const en = JSON.parse(await readFile(new URL('i18n/en.json', root), 'utf8')).api_tokens

  const used = new Set([...page.matchAll(/api_tokens\.(mcp_[a-z_]+)/g)].map(m => m[1]))
  assert.ok(used.size >= 5, 'the page should use the MCP help texts')
  for (const key of used) {
    assert.ok(cs[key] && cs[key].trim() !== '', `cs.json: api_tokens.${key}`)
    assert.ok(en[key] && en[key].trim() !== '', `en.json: api_tokens.${key}`)
  }

  const csKeys = Object.keys(cs).filter(k => k.startsWith('mcp_')).sort()
  const enKeys = Object.keys(en).filter(k => k.startsWith('mcp_')).sort()
  assert.deepEqual(csKeys, enKeys)
  for (const key of csKeys) assert.ok(used.has(key), `api_tokens.${key} is translated but unused`)
})

test('the token page shows the snippets from the helper instead of hard-coded text', async () => {
  const page = await readFile(new URL('pages/ApiTokens.vue', root), 'utf8')

  assert.match(page, /from '@\/utils\/mcpConnect'/)
  assert.doesNotMatch(page, /claude mcp add/)
  assert.doesNotMatch(page, /mcp-remote/)
})

test('MCP help texts avoid vue-i18n message syntax characters', async () => {
  for (const locale of ['cs', 'en']) {
    const block = JSON.parse(await readFile(new URL(`i18n/${locale}.json`, root), 'utf8')).api_tokens
    for (const [key, value] of Object.entries(block)) {
      if (!key.startsWith('mcp_')) continue
      assert.doesNotMatch(value, /[{}|@]/, `${locale}: api_tokens.${key} would be parsed by vue-i18n`)
    }
  }
})
