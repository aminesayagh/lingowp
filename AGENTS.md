
<!-- BEGIN project-additions -->
<!-- Injected after the MCP-generated context. Safe to edit. Keep these markers
     intact so re-running inject-context.sh stays idempotent. -->

## Working agreement (project additions)

These instructions extend the base context above and take priority where they conflict with default behavior.

### Clarify before acting
- If a request is ambiguous, underspecified, or missing details needed to choose an implementation approach, ask before writing or changing code. Do not fill gaps with assumptions.
- When a tool supports elicitation, use it to request the missing parameters rather than guessing inputs.
- A short list of clarifying questions is always preferable to a confident but wrong implementation.

### Plan before you build
- For any non-trivial change, outline the approach first — files to touch, the strategy, and the trade-offs — and wait for my go-ahead before implementing.
- Make the plan explicit and reviewable. I want to see and approve the approach up front, not review it only after the code already exists.

### Searching the codebase
- Use **ast-grep** for structural, syntax-aware search: finding patterns, call sites, usages, and refactor targets. Prefer it over plain-text grep whenever the query is about code structure rather than a literal string.
- Use **serena** for semantic, symbol-level navigation (definitions, references, types).
- Use **code-review-graph** to understand the impact and blast radius of a change before finalizing it.

### Library and API docs
- Before using or recommending an API, framework feature, or library version, pull the current docs via **ctx7** (Context7). Do not rely on memory for API signatures — verify, to avoid outdated or hallucinated APIs.
- Match the version actually used in this project, not the latest in general.

### Testing stays under my control
- Do not run, modify, or reconfigure the test process on your own. Propose tests and the exact commands to run them, and let me execute them.
- Never claim tests pass without my having run them. Report what you expect to happen, not what you assume happened.

### Implementation discipline
- I keep final say on implementation approach. Favor the clearest, most maintainable option that fits existing patterns over the cleverest one.
- No destructive or irreversible actions (deleting files, rewriting git history, sweeping refactors) without explicit confirmation.

### Code organization preference
- Prefer feature-local, readable flow over indirection. When understanding a feature, it should usually be possible to open one relevant file and follow the business flow, even if that means a longer class or method.
- Avoid adding generic infrastructure, service containers, registries, factories, or orchestration layers unless they solve a concrete business problem in this plugin. Do not introduce indirection just to satisfy an abstract pattern.
- Adding explicit fields, dependencies, or straightforward methods to an existing class is acceptable when it keeps the flow easier to read.
- In plans and explanations, use natural language about the product behavior and implementation intent. Avoid framing proposals around coding-tool jargon or abstractions when a direct explanation is clearer.
<!-- END project-additions -->
