/**
 * A stand-in for the browser's `document.modelContext`, for browsers without WebMCP
 * (Cypress's Electron and Chrome have it switched off).
 *
 * It follows the WebMCP draft as far as the plugin uses it: registerTool() with the
 * name and description checks and a duplicate check, unregistering through the
 * signal, getTools(), and executeTool(), which resolves to the result as JSON.
 *
 * Install it before the page's scripts run: `cy.visit(url, { onBeforeLoad: installModelContext })`.
 */
export function installModelContext(win) {
  const tools = new Map();

  Object.defineProperty(win.document, 'modelContext', {
    configurable: true,
    value: {
      async registerTool(tool, options = {}) {
        if (!/^[A-Za-z0-9_.-]{1,128}$/.test(tool.name) || !tool.description) {
          throw new win.DOMException(`Invalid tool ${tool.name}`, 'InvalidStateError');
        }

        if (tools.has(tool.name)) {
          throw new win.DOMException(`Duplicate tool ${tool.name}`, 'InvalidStateError');
        }

        tools.set(tool.name, tool);
        options.signal?.addEventListener('abort', () => tools.delete(tool.name));
      },

      async getTools() {
        return [...tools.values()]
          .map(({ execute, ...definition }) => definition)
          .sort((a, b) => a.name.localeCompare(b.name));
      },

      async executeTool(tool, input = {}) {
        const registered = tools.get(tool.name ?? tool);

        if (!registered) {
          throw new win.DOMException(`No tool ${tool.name ?? tool}`, 'NotFoundError');
        }

        return JSON.stringify(await registered.execute(input, { signal: new win.AbortController().signal }));
      },
    },
  });
}

/**
 * Call a registered tool the way an agent does, and parse its JSON result.
 */
export function callTool(win, name, input = {}) {
  return win.document.modelContext.executeTool({ name }, input).then((json) => JSON.parse(json));
}
