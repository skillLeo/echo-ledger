// The user guide's Markdown as React elements, never as HTML: headings, paragraphs, lists, tables, quotes, bold and
// code. A link shows its words only (the in-app guide has no links to follow, so no target is ever clickable). Anything
// else reads as plain text. The text comes from the server, so the parser is bounded: every step consumes at least one
// line, and at most MAX_LINES lines and MAX_BLOCKS blocks are read.
import type { ReactNode } from "react";

type Block =
  | { kind: "heading"; level: number; text: string }
  | { kind: "paragraph"; text: string }
  | { kind: "quote"; text: string }
  | { kind: "list"; ordered: boolean; items: string[] }
  | { kind: "table"; head: string[]; rows: string[][] }
  | { kind: "code"; text: string };

const LIST = /^\s*(?:[-*+]|(\d+)[.)])\s+(.*)$/;
const cells = (line: string) => line.trim().replace(/^\||\|$/g, "").split("|").map((c) => c.trim());

/** At most this many lines of a guide are read, and this many blocks made. */
export const MAX_LINES = 5000;
export const MAX_BLOCKS = 5000;
/** And at most this many characters. */
export const MAX_CHARS = 400_000;

/** The blocks of a Markdown text, in order. */
export function blocks(markdown: string): Block[] {
  const lines = markdown.slice(0, MAX_CHARS).replace(/\r\n?/g, "\n").split("\n", MAX_LINES);
  const out: Block[] = [];
  let i = 0;
  while (i < lines.length && out.length < MAX_BLOCKS) {
    const line = lines[i];
    if (!line.trim()) { i++; continue; }
    if (line.trim().startsWith("```")) {
      const body: string[] = [];
      i++;
      while (i < lines.length && !lines[i].trim().startsWith("```")) body.push(lines[i++]);
      i++;
      out.push({ kind: "code", text: body.join("\n") });
      continue;
    }
    const heading = /^(#{1,6})\s+(.*?)\s*#*\s*$/.exec(line);
    if (heading) { out.push({ kind: "heading", level: heading[1].length, text: heading[2] }); i++; continue; }
    if (line.trim().startsWith("|") && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}/.test(lines[i + 1])) {
      const head = cells(line);
      const rows: string[][] = [];
      i += 2;
      while (i < lines.length && lines[i].trim().startsWith("|")) rows.push(cells(lines[i++]));
      out.push({ kind: "table", head, rows });
      continue;
    }
    if (line.trim().startsWith(">")) {
      const body: string[] = [];
      while (i < lines.length && lines[i].trim().startsWith(">")) body.push(lines[i++].trim().replace(/^>\s?/, ""));
      out.push({ kind: "quote", text: body.join(" ") });
      continue;
    }
    const item = LIST.exec(line);
    if (item) {
      const ordered = item[1] !== undefined;
      // The first item is always taken, whatever its indent, so the list moves past its own line.
      const items: string[] = [item[2]];
      i++;
      while (i < lines.length) {
        const m = LIST.exec(lines[i]);
        if (m && (m[1] !== undefined) === ordered && !/^\s{2,}/.test(lines[i])) { items.push(m[2]); i++; continue; }
        // An indented line (or a nested item) continues the item above it.
        if (lines[i].trim() && /^\s+/.test(lines[i]) && items.length) { items[items.length - 1] += ` ${lines[i].trim().replace(/^(?:[-*+]|\d+[.)])\s+/, "")}`; i++; continue; }
        break;
      }
      out.push({ kind: "list", ordered, items });
      continue;
    }
    // A paragraph: this line whatever it looks like (a "|" without a table, "#nospace"), then the lines that follow
    // until a line that starts another block.
    const body: string[] = [line.trim()];
    i++;
    while (i < lines.length && lines[i].trim() && !/^(#{1,6})\s/.test(lines[i]) && !LIST.exec(lines[i]) && !lines[i].trim().startsWith("|") && !lines[i].trim().startsWith(">")) {
      body.push(lines[i++].trim());
    }
    out.push({ kind: "paragraph", text: body.join(" ") });
  }
  return out;
}

/** Bold, code and links' words inside a line. */
export function inline(text: string): ReactNode[] {
  const out: ReactNode[] = [];
  // Each mark spans a bounded stretch, so a line full of unmatched "**" or "[" stays linear.
  const pattern = /\*\*([^*\n]{1,300})\*\*|`([^`\n]{1,300})`|\[([^\]\n]{1,300})\]\((?:[^()\s]|\([^()\s]{0,100}\)){0,500}\)/g;
  let last = 0;
  let m: RegExpExecArray | null;
  while ((m = pattern.exec(text))) {
    if (m.index > last) out.push(text.slice(last, m.index));
    if (m[1] !== undefined) out.push(<strong key={m.index}>{inline(m[1])}</strong>);
    else if (m[2] !== undefined) out.push(<code key={m.index} className="el-code">{m[2]}</code>);
    else out.push(m[3]);
    last = m.index + m[0].length;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

/** A guide's text. Headings start one level under the page's (`top`), so the outline stays in order. */
export function Markdown({ text, top = 2 }: { text: string; top?: number }) {
  return (
    <div className="el-markdown" dir="auto">
      {blocks(text).map((b, i) => {
        switch (b.kind) {
          case "heading": {
            const level = Math.min(6, top + b.level - 1);
            const H = `h${level}` as "h2";
            return <H key={i} className="el-body-strong">{inline(b.text)}</H>;
          }
          case "paragraph": return <p key={i}>{inline(b.text)}</p>;
          case "quote": return <blockquote key={i}>{inline(b.text)}</blockquote>;
          case "code": return <pre key={i} className="el-code" dir="ltr">{b.text}</pre>;
          case "list": {
            const L = b.ordered ? "ol" : "ul";
            return <L key={i}>{b.items.map((t, j) => <li key={j}>{inline(t)}</li>)}</L>;
          }
          case "table":
            return (
              <div key={i} className="el-markdown__table">
                <table>
                  <thead><tr>{b.head.map((h, j) => <th key={j} scope="col">{inline(h)}</th>)}</tr></thead>
                  <tbody>{b.rows.map((r, j) => <tr key={j}>{r.map((c, k) => <td key={k}>{inline(c)}</td>)}</tr>)}</tbody>
                </table>
              </div>
            );
        }
      })}
    </div>
  );
}
