/** Ready-to-paste connection snippets for the MCP endpoint (AI assistants). */

/** Placeholder shown instead of a real token; the plain-text token is only visible once, at creation. */
export const MCP_TOKEN_PLACEHOLDER = 'mi_pat_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX'

/** `origin` as in `window.location.origin`, e.g. `https://faktury.example.cz`. */
export function mcpEndpoint(origin: string): string {
  return `${origin}/api/mcp`
}

/** Claude Code: registers the server over the Streamable HTTP transport with a bearer header. */
export function claudeCodeCommand(endpoint: string): string {
  return `claude mcp add --transport http myinvoice ${endpoint} --header "Authorization: Bearer ${MCP_TOKEN_PLACEHOLDER}"`
}

/**
 * Claude Desktop: its config file launches local processes, so the remote endpoint goes through the
 * `mcp-remote` bridge. The header value lives in `env` because spaces inside `args` are mangled on Windows.
 */
export function claudeDesktopConfig(endpoint: string): string {
  const args = ['-y', 'mcp-remote', endpoint, '--header', 'Authorization:${MYINVOICE_AUTH}']
  return [
    '{',
    '  "mcpServers": {',
    '    "myinvoice": {',
    '      "command": "npx",',
    `      "args": ${JSON.stringify(args)},`,
    `      "env": { "MYINVOICE_AUTH": ${JSON.stringify(`Bearer ${MCP_TOKEN_PLACEHOLDER}`)} }`,
    '    }',
    '  }',
    '}',
  ].join('\n')
}
