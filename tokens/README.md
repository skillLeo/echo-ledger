# tokens — EchoLedger's design values

One source for colours, type, spacing, radii, motion and shadows, in W3C design-token JSON:

- `global/`: raw values (the palette, the 4 px spacing scale, radii, type sizes, motion, shadows);
- `semantic/`: roles that components use (`light.json` and `dark.json` for colours, `layout.json` for sizes, `tint.json`
  for how much of a colour role a tint lays over a surface);
- `component/`: values that belong to one component (button height, dialog radius, toast duration).

Components use semantic roles and component values only, never the raw palette.

## Build

```sh
cd tokens
npm ci
npm run build   # writes the three outputs below
npm test        # contrast and accent checks, and fails when an output is out of date
```

| Output | Used by |
|---|---|
| `web/resources/css/tokens.css` | The web console and website: CSS variables `--el-*` plus a Tailwind v4 `@theme` (utilities such as `bg-el-bg-canvas`) |
| `desktop/src/tokens.css` | The desktop app's webview: CSS variables `--el-*` |
| `mobile/app/src/main/java/com/echoledger/mobile/ui/v2/tokens/EchoTokens.kt` | The Android app: `EchoTokens.Light`, `.Dark`, `.Space`, `.Radius`, `.Layout`, `.Tint`, `.Component`, `.Motion`, `.Type` |

The outputs are generated; edit the JSON here instead. Dark mode follows the system unless `data-theme` on the root
element says `light` or `dark`. The names are fixed; the values are a proposal until the visual direction is chosen
(see [docs/v2/DESIGN_SYSTEM.md](../docs/v2/DESIGN_SYSTEM.md)).
