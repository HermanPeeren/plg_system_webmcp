/**
 * @package     WebMcp
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 *
 * Registers the site's tools with the browser's WebMCP API, so an AI agent in the
 * browser can call them instead of clicking through the pages.
 *
 * The tools are defined on the server (the plugin puts their definitions in the
 * script options). Here each one only gets an execute() that posts the agent's input
 * to the site and returns the answer. So this file never changes when a tool is added.
 *
 * WebMCP is a draft standard (W3C Web Machine Learning Community Group). This follows
 * the draft of November 2026: `document.modelContext.registerTool(tool, { signal })`.
 */

const options = window.Joomla?.getOptions('plg_system_webmcp');

/**
 * The browser's model context, if it has one. Earlier drafts and previews put it on
 * `navigator`; the current draft has it on `document`.
 */
const modelContext = document.modelContext ?? navigator.modelContext;

/**
 * Lets the tools be unregistered (with abort()), e.g. by a page that replaces them.
 */
const registration = new AbortController();

/**
 * Call one tool on the server.
 *
 * @param {string} name     The tool's name.
 * @param {object} input    The agent's input, checked again on the server.
 * @param {AbortSignal} [signal]  Aborted when the agent cancels the call.
 *
 * @returns {Promise<object>} The tool's result.
 * @throws {Error} With the site's message when the tool fails.
 */
async function callTool(name, input, signal) {
  const body = new FormData();
  body.append(options.token, '1');
  body.append('tool', name);
  body.append('input', JSON.stringify(input ?? {}));

  const response = await fetch(options.endpoint, {
    method: 'POST',
    body,
    signal,
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });

  if (!response.ok) {
    throw new Error(`The site answered with HTTP ${response.status}.`);
  }

  // com_ajax wraps a plugin's answer: { success, message, data: answer }. With more
  // than one listener, data is a list of their answers.
  const json = await response.json();
  const answer = Array.isArray(json?.data) ? json.data[0] : json?.data;

  if (!json.success || !answer) {
    throw new Error(json?.message || 'The site gave no answer.');
  }

  if (!answer.ok) {
    throw new Error(answer.error);
  }

  // A tool can ask to take the visitor to a page, e.g. the checkout. That is done
  // after the agent has the answer: leaving the page ends this script.
  if (typeof answer.result?.navigate_to === 'string') {
    const target = new URL(answer.result.navigate_to, window.location.href);

    if (target.origin === window.location.origin) {
      window.setTimeout(() => window.location.assign(target.href), 500);
    }
  }

  return answer.result;
}

/**
 * Run a tool for the agent. A failure is returned as the result, `{ error: message }`,
 * not thrown: the browser does not pass an exception's message on to the agent
 * (Chrome reports only "Tool execution failed."), and the message is what tells the
 * agent what to do instead ("Color is required", "there is no property value Purple").
 * A cancelled call is still thrown, as the browser expects.
 */
async function executeForAgent(name, input, signal) {
  try {
    return await callTool(name, input, signal);
  } catch (error) {
    if (error?.name === 'AbortError') {
      throw error;
    }

    return { error: error?.message || 'The tool failed.' };
  }
}

async function registerTools() {
  for (const definition of options.tools) {
    const tool = {
      ...definition,
      execute: (input, executeOptions) => executeForAgent(definition.name, input, executeOptions?.signal),
    };

    try {
      await modelContext.registerTool(tool, { signal: registration.signal });
    } catch (error) {
      // One tool the browser refuses must not keep the others away.
      console.warn(`WebMCP: tool ${definition.name} was not registered.`, error);
    }
  }

  document.dispatchEvent(new CustomEvent('webmcp:registered', { detail: { tools: options.tools.map((t) => t.name) } }));
}

if (options?.tools?.length && typeof modelContext?.registerTool === 'function') {
  registerTools();
}

// For debugging and for tests: what this page offers, and a way to call it.
window.JoomlaWebMcp = { options, callTool, registration };
