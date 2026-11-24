import type { HastPluginDefinition } from "satteri";

type Node = {
  type: string;
  tagName?: string;
  value?: string;
  properties?: Record<string, unknown>;
  children?: Node[];
};

function text(node: Node): string {
  if (node.type === "text") return node.value ?? "";
  return (node.children ?? []).map(text).join("");
}

function isMermaid(node: Node): boolean {
  const p = node.properties ?? {};
  const classes = ([] as unknown[]).concat(p.className ?? []).map(String);
  return (
    p.dataNbLang === "mermaid" ||
    p["data-nb-lang"] === "mermaid" ||
    p.dataLanguage === "mermaid" ||
    classes.includes("language-mermaid")
  );
}

/**
 * Turns ```mermaid fences into the <pre class="mermaid"> blocks that
 * src/scripts/mermaid.ts draws in the browser, so pages write diagrams as
 * plain fences in .md and .mdx alike.
 */
export function mermaidFences(): HastPluginDefinition {
  return {
    name: "sovra:mermaid-fences",
    element: {
      filter: ["figure", "pre"],
      visit(node: Node) {
        if (!isMermaid(node)) {
          const code = node.tagName === "pre" ? node.children?.find((c) => c.tagName === "code") : undefined;
          if (!code || !isMermaid(code)) return;
        }
        if ((node.properties?.className as string[] | undefined)?.includes("mermaid")) return;
        return {
          type: "element",
          tagName: "pre",
          properties: { className: ["mermaid"] },
          children: [{ type: "text", value: text(node).replace(/\n+$/, "") }],
        };
      },
    },
  } as HastPluginDefinition;
}
