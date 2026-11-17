//! Waiting for the main thread, and the one rule that keeps it from freezing EchoLedger.
//!
//! Tauri runs every synchronous command, the tray's and the menu's clicks, the global shortcuts' key presses and every
//! window event on the main thread. From any other thread, many of its calls post a task to the main thread and wait
//! for the answer: building a window; every tray and menu change (`set_icon`, `set_menu`, `set_tooltip`, a new menu or
//! menu item); a window's size, position, monitor, visibility or native handle; the displays and the cursor; a global
//! shortcut's change. EchoLedger's own waits do the same (`run_on_main_thread`, then a `recv` of the answer).
//!
//! When a thread holds one of EchoLedger's locks while it waits, and the main thread wants that lock (a sync command
//! reads the status, a key press looks up its shortcut), neither moves again and the app freezes. That froze the Mac
//! build of 2026-11-16 during a whole-screen share: tray drawing held the tray's lock while it waited, and a command
//! on the main thread waited for that lock. The rule: read what is needed under the lock, let the guard go, then wait.
//!
//! macOS can make the main thread wait too: asked whether EchoLedger may quit (the Dock's Quit, logging out), it is told
//! to wait while a running recording is kept (`app_life`), and AppKit keeps the main thread in a modal loop until the
//! answer. EchoLedger's own tasks still run there, the keeping never needs the main thread to end, and a timer on the
//! main queue answers after a time limit whatever happens. A `exec_sync` on the main queue waits for the main thread
//! like any of the calls below.
//!
//! The tests below hold the whole crate to it. They read every source file, find every lock guard (`lock()`, `read()`,
//! `write()`, or a function that returns one; kept in a binding, or as a temporary of a statement, an `if let`, a
//! `match` or a `for`, by Rust's own scope rules) and fail when a call that may wait for the main thread runs while one
//! is held: a Tauri call from the lists below, or any EchoLedger function that reaches one.
//!
//! What the check cannot see, so code review still must:
//! - calls through a struct field, a function pointer or a closure kept in a variable (the screens sampler's shield, a
//!   boxed callback): a closure is read where it is written, not where it is called;
//! - a name shadowed by a local variable or parameter (a call to a local `f` is taken for the caller's closure);
//! - `join()` on a thread that itself waits for the main thread (the lock holder waits for the thread, the thread for
//!   the main thread);
//! - macro bodies that do not parse as expressions (`tokio::select!`, `json!`, `vec![x; n]`);
//! - calls that wait but whose names other types share, so they are not listed: a menu's `append`, `insert`, `remove`
//!   and `items`, a window's `title()`, a webview's `size()`, `position()` and `bounds()`;
//! - which type a method belongs to: a method call is matched by its name only (`self.x()` is not narrowed to `Self::x`),
//!   which errs on the side of a finding.

#[cfg(test)]
mod tests {
    use std::collections::{BTreeMap, BTreeSet};
    use std::path::Path;

    use quote::ToTokens;
    use syn::punctuated::Punctuated;
    use syn::spanned::Spanned;
    use syn::visit::Visit;
    use syn::{Attribute, BinOp, Block, Expr, ExprCall, ExprIf, ExprMethodCall, FnArg, ImplItemFn, ItemFn, ItemImpl, ItemMod, ItemTrait, Pat, ReturnType, Signature, Stmt, TraitItemFn, Type};

    // ---- What waits for the main thread (tauri 2.12.5, tauri-runtime-wry 2.12.3, the plugins as locked) -------------

    /// Tray and menu changes: posted to the main thread and waited for (`run_item_main_thread!`). A window's `set_enabled`
    /// and `set_title` only post, and a file dialog's `set_title` neither: counting them errs on the side of a finding.
    const TRAY_AND_MENU: [&str; 18] = [
        "set_icon",
        "set_icon_with_as_template",
        "set_icon_as_template",
        "set_menu",
        "set_tooltip",
        "set_show_menu_on_left_click",
        "set_temp_dir_path",
        "with_inner_tray_icon",
        "set_text",
        "set_checked",
        "set_accelerator",
        "set_as_app_menu",
        "set_as_window_menu",
        "popup",
        "popup_at",
        "set_enabled",
        "set_visible",
        "set_title",
    ];

    /// A window's or a webview's answers, the displays and the cursor: a message to the event loop and a wait for the
    /// reply (`window_getter!`, `event_loop_window_getter!`), the native handles, and the tray icon's `rect()`.
    const GETTERS: [&str; 27] = [
        "is_visible",
        "is_focused",
        "is_minimized",
        "is_maximized",
        "is_fullscreen",
        "is_decorated",
        "is_resizable",
        "is_always_on_top",
        "is_closable",
        "is_minimizable",
        "is_maximizable",
        "inner_size",
        "outer_size",
        "inner_position",
        "outer_position",
        "current_monitor",
        "primary_monitor",
        "available_monitors",
        "cursor_position",
        "ns_window",
        "ns_view",
        "hwnd",
        "gtk_window",
        "default_vbox",
        "window_handle",
        "is_devtools_open",
        "rect",
    ];

    /// Getters whose names other types share (a monitor's `scale_factor` is a plain number): a window's comes as a
    /// `Result`, so they count where the answer is used as one.
    const RESULT_GETTERS: [&str; 4] = ["scale_factor", "theme", "url", "is_enabled"];

    /// What turns a getter's answer into a `Result` being used.
    const RESULT_USE: [&str; 15] = ["ok", "err", "unwrap", "unwrap_or", "unwrap_or_default", "unwrap_or_else", "expect", "map", "map_or", "map_or_else", "and_then", "is_ok", "is_err", "map_err", "ok_or"];

    /// Types built on the main thread and waited for: menus, menu items and the tray icon.
    const MAIN_THREAD_TYPES: [&str; 12] = [
        "Menu",
        "MenuItem",
        "CheckMenuItem",
        "IconMenuItem",
        "PredefinedMenuItem",
        "Submenu",
        "MenuBuilder",
        "SubmenuBuilder",
        "MenuItemBuilder",
        "CheckMenuItemBuilder",
        "IconMenuItemBuilder",
        "TrayIconBuilder",
    ];

    /// The global shortcut plugin: each change runs on the main thread and is waited for (its `run_main_thread!`).
    const SHORTCUTS: [&str; 6] = ["register", "unregister", "on_shortcut", "on_shortcuts", "register_multiple", "unregister_multiple"];

    /// Never called: tauri-plugin-global-shortcut 2.4.1's `unregister_all` holds its own shortcut table while it waits
    /// for the main thread, and the main thread takes that table for every key press.
    const NEVER: [&str; 1] = ["unregister_all"];

    /// The closures given to these run later (on the main thread, a task or a thread of their own): each is read as a
    /// unit of its own, with no guard of the code that gave it.
    const LATER: [&str; 22] = [
        "spawn",
        // The blocking pool's thread (window builds, `lifecycle::spawn_build`): like a thread of its own.
        "spawn_blocking",
        "init",
        "on_menu_event",
        "on_tray_icon_event",
        "on_window_event",
        "on_webview_event",
        "on_page_load",
        "setup",
        "with_handler",
        "on_shortcut",
        "on_shortcuts",
        "listen",
        "listen_any",
        "once",
        "once_any",
        "run",
        "set_event_handler",
        "register_uri_scheme_protocol",
        "register_asynchronous_uri_scheme_protocol",
        "on_navigation",
        "on_new_window",
    ];

    // ---- The crate's functions ------------------------------------------------------------------------------------

    /// One function of the crate.
    #[derive(Clone)]
    struct Fun {
        file: String,
        module: Vec<String>,
        name: String,
        self_ty: Option<String>,
        method: bool,
        params: Vec<String>,
        returns_guard: bool,
        builds_windows: bool,
        body: Block,
    }

    impl Fun {
        fn label(&self) -> String {
            let mut parts = self.module.clone();
            parts.extend(self.self_ty.clone());
            parts.push(self.name.clone());
            parts.join("::")
        }
    }

    /// Test code only: `#[test]` and `#[tokio::test]` (an attribute whose path is `test` or ends in `::test`), or a `cfg`
    /// whose condition cannot hold outside tests (`cfg(test)`, `cfg(all(…, test, …))`). Doc lines are attributes too and
    /// never count, whatever word they end in; `cfg(any(windows, test))` and `cfg(not(test))` are the app's own code.
    fn is_test(attrs: &[Attribute]) -> bool {
        attrs.iter().any(|a| {
            let path = a.path();
            if path.is_ident("doc") {
                return false;
            }
            if path.segments.last().is_some_and(|s| s.ident == "test") {
                return true;
            }
            path.is_ident("cfg") && a.parse_args::<syn::Meta>().is_ok_and(|condition| requires_test(&condition))
        })
    }

    /// Whether a `cfg` condition holds only in tests (pure).
    fn requires_test(condition: &syn::Meta) -> bool {
        let parts = |list: &syn::MetaList| list.parse_args_with(Punctuated::<syn::Meta, syn::Token![,]>::parse_terminated).map(|p| p.into_iter().collect::<Vec<_>>()).unwrap_or_default();
        match condition {
            syn::Meta::Path(p) => p.is_ident("test"),
            syn::Meta::List(list) if list.path.is_ident("all") => parts(list).iter().any(requires_test),
            syn::Meta::List(list) if list.path.is_ident("any") => {
                let alternatives = parts(list);
                !alternatives.is_empty() && alternatives.iter().all(requires_test)
            }
            // `not(…)`, `target_os = "…"`, `windows` and the rest hold outside tests.
            _ => false,
        }
    }

    fn type_name(ty: &Type) -> Option<String> {
        match ty {
            Type::Path(p) => p.path.segments.last().map(|s| s.ident.to_string()),
            Type::Reference(r) => type_name(&r.elem),
            _ => None,
        }
    }

    fn pattern_names(pat: &Pat) -> Vec<String> {
        struct Names(Vec<String>);
        impl<'ast> Visit<'ast> for Names {
            fn visit_pat_ident(&mut self, i: &'ast syn::PatIdent) {
                self.0.push(i.ident.to_string());
                syn::visit::visit_pat_ident(self, i);
            }
        }
        let mut names = Names(Vec::new());
        names.visit_pat(pat);
        names.0
    }

    struct Collect<'a> {
        file: &'a str,
        module: Vec<String>,
        self_ty: Vec<Option<String>>,
        out: Vec<Fun>,
        /// `use a::b as c;` anywhere in the file: `c` → `a::b`.
        aliases: BTreeMap<String, Vec<String>>,
    }

    fn use_aliases(tree: &syn::UseTree, prefix: &mut Vec<String>, out: &mut BTreeMap<String, Vec<String>>) {
        match tree {
            syn::UseTree::Path(p) => {
                prefix.push(p.ident.to_string());
                use_aliases(&p.tree, prefix, out);
                prefix.pop();
            }
            syn::UseTree::Rename(r) if r.rename != "_" => {
                let mut target = prefix.clone();
                target.push(r.ident.to_string());
                out.insert(r.rename.to_string(), target);
            }
            syn::UseTree::Group(g) => g.items.iter().for_each(|t| use_aliases(t, prefix, out)),
            _ => {}
        }
    }

    impl Collect<'_> {
        fn add(&mut self, sig: &Signature, block: &Block, self_ty: Option<String>) {
            let output = match &sig.output {
                ReturnType::Type(_, ty) => ty.to_token_stream().to_string(),
                ReturnType::Default => String::new(),
            };
            self.out.push(Fun {
                file: self.file.to_string(),
                module: self.module.clone(),
                name: sig.ident.to_string(),
                self_ty,
                method: matches!(sig.inputs.first(), Some(FnArg::Receiver(_))),
                params: sig
                    .inputs
                    .iter()
                    .filter_map(|a| match a {
                        FnArg::Typed(t) => Some(pattern_names(&t.pat).into_iter().next().unwrap_or_else(|| "_".into())),
                        FnArg::Receiver(_) => None,
                    })
                    .collect(),
                returns_guard: ["MutexGuard", "RwLockReadGuard", "RwLockWriteGuard"].iter().any(|g| output.contains(g)),
                builds_windows: sig.to_token_stream().to_string().contains("WebviewWindowBuilder"),
                body: block.clone(),
            });
        }
    }

    impl<'ast> Visit<'ast> for Collect<'_> {
        fn visit_item_use(&mut self, u: &'ast syn::ItemUse) {
            use_aliases(&u.tree, &mut Vec::new(), &mut self.aliases);
        }

        fn visit_item_mod(&mut self, m: &'ast ItemMod) {
            if is_test(&m.attrs) {
                return;
            }
            if let Some((_, items)) = &m.content {
                self.module.push(m.ident.to_string());
                for item in items {
                    self.visit_item(item);
                }
                self.module.pop();
            }
        }

        fn visit_item_impl(&mut self, i: &'ast ItemImpl) {
            if is_test(&i.attrs) {
                return;
            }
            self.self_ty.push(type_name(&i.self_ty));
            for item in &i.items {
                self.visit_impl_item(item);
            }
            self.self_ty.pop();
        }

        fn visit_item_trait(&mut self, t: &'ast ItemTrait) {
            if is_test(&t.attrs) {
                return;
            }
            self.self_ty.push(Some(t.ident.to_string()));
            for item in &t.items {
                self.visit_trait_item(item);
            }
            self.self_ty.pop();
        }

        fn visit_item_fn(&mut self, f: &'ast ItemFn) {
            if is_test(&f.attrs) {
                return;
            }
            self.add(&f.sig, &f.block, None);
            self.self_ty.push(None);
            self.visit_block(&f.block);
            self.self_ty.pop();
        }

        fn visit_impl_item_fn(&mut self, f: &'ast ImplItemFn) {
            if is_test(&f.attrs) {
                return;
            }
            let self_ty = self.self_ty.last().cloned().flatten();
            self.add(&f.sig, &f.block, self_ty);
            self.visit_block(&f.block);
        }

        fn visit_trait_item_fn(&mut self, f: &'ast TraitItemFn) {
            if is_test(&f.attrs) {
                return;
            }
            if let Some(block) = &f.default {
                let self_ty = self.self_ty.last().cloned().flatten();
                self.add(&f.sig, block, self_ty);
                self.visit_block(block);
            }
        }
    }

    /// `src/privacy/check.rs` → `["privacy", "check"]`.
    fn module_of(path: &str) -> Vec<String> {
        let trimmed = path.trim_start_matches("src/").trim_end_matches(".rs");
        let mut parts: Vec<String> = trimmed.split('/').map(str::to_string).collect();
        if matches!(parts.last().map(String::as_str), Some("mod" | "lib" | "main")) {
            parts.pop();
        }
        parts
    }

    // ---- Calls ------------------------------------------------------------------------------------------------------

    #[derive(Clone, Debug)]
    enum Callee {
        Method(String),
        Path(Vec<String>),
    }

    #[derive(Clone, Debug)]
    enum Call {
        /// Waits for the main thread, and why.
        Waits(String),
        /// Must never be made.
        Never(String),
        /// One of EchoLedger's functions (the ones it may be).
        Ours(Vec<usize>),
    }

    #[derive(Clone, Debug)]
    struct Event {
        line: usize,
        text: String,
        call: Call,
        /// The guards held while the call runs.
        held: Vec<String>,
    }

    /// A function, or a closure that runs on its own (a spawned task or thread, a handler).
    struct Unit {
        file: String,
        label: String,
        fun: Option<usize>,
        events: Vec<Event>,
    }

    struct Index {
        funs: Vec<Fun>,
        /// The parameters each function calls while it holds a guard (a closure it runs under its lock).
        guarded: Vec<BTreeSet<usize>>,
        methods: BTreeMap<String, Vec<usize>>,
        free: BTreeMap<String, Vec<usize>>,
        assoc: BTreeMap<(String, String), Vec<usize>>,
    }

    /// Where a walk is: the function (or closure) and its module, for the names it calls.
    #[derive(Clone)]
    struct Ctx {
        file: String,
        module: Vec<String>,
        self_ty: Option<String>,
        params: Vec<String>,
        builds_windows: bool,
        label: String,
        /// The file's `use … as …` names.
        aliases: BTreeMap<String, Vec<String>>,
    }

    impl Index {
        fn new(funs: Vec<Fun>) -> Self {
            let mut index = Self { guarded: vec![BTreeSet::new(); funs.len()], funs, methods: BTreeMap::new(), free: BTreeMap::new(), assoc: BTreeMap::new() };
            for (i, f) in index.funs.iter().enumerate() {
                if f.method {
                    index.methods.entry(f.name.clone()).or_default().push(i);
                }
                match &f.self_ty {
                    Some(ty) => index.assoc.entry((ty.clone(), f.name.clone())).or_default().push(i),
                    None => index.free.entry(f.name.clone()).or_default().push(i),
                }
            }
            index
        }

        /// The crate's functions a call may be (by name; a method by its name alone, the receiver's type is unknown).
        fn resolve(&self, callee: &Callee, ctx: &Ctx) -> Vec<usize> {
            match callee {
                Callee::Method(name) => self.methods.get(name).cloned().unwrap_or_default(),
                Callee::Path(segments) => {
                    // A name brought in with `use … as …` stands for its path.
                    let segments: Vec<String> = match segments.first().and_then(|first| ctx.aliases.get(first)) {
                        Some(target) => target.iter().chain(&segments[1..]).cloned().collect(),
                        None => segments.clone(),
                    };
                    let Some((name, quals)) = segments.split_last() else { return Vec::new() };
                    let all = self.free.get(name).cloned().unwrap_or_default();
                    let Some(last) = quals.last() else {
                        let here: Vec<usize> = all.iter().copied().filter(|&i| self.funs[i].module == ctx.module).collect();
                        return if here.is_empty() { all } else { here };
                    };
                    if last == "Self" {
                        return ctx.self_ty.as_ref().and_then(|t| self.assoc.get(&(t.clone(), name.clone()))).cloned().unwrap_or_default();
                    }
                    if last.starts_with(|c: char| c.is_uppercase()) {
                        return self.assoc.get(&(last.clone(), name.clone())).cloned().unwrap_or_default();
                    }
                    let (base, rest): (Option<Vec<String>>, &[String]) = match quals[0].as_str() {
                        "crate" => (Some(Vec::new()), &quals[1..]),
                        "self" => (Some(ctx.module.clone()), &quals[1..]),
                        "super" => {
                            let ups = quals.iter().take_while(|q| *q == "super").count();
                            let mut module = ctx.module.clone();
                            module.truncate(module.len().saturating_sub(ups));
                            (Some(module), &quals[ups..])
                        }
                        _ => (None, quals),
                    };
                    all.into_iter()
                        .filter(|&i| {
                            let module = &self.funs[i].module;
                            match &base {
                                Some(base) => module.len() == base.len() + rest.len() && module.starts_with(base) && module.ends_with(rest),
                                None => module.ends_with(rest),
                            }
                        })
                        .collect()
                }
            }
        }
    }

    fn segments(path: &syn::Path) -> Vec<String> {
        path.segments.iter().map(|s| s.ident.to_string()).collect()
    }

    /// Source text in one line, the way it reads.
    fn text(node: &impl ToTokens) -> String {
        let raw = node.to_token_stream().to_string();
        let mut out = raw.replace(" . ", ".").replace(" (", "(").replace("( ", "(").replace(" )", ")").replace(" ,", ",").replace(" :: ", "::").replace(":: ", "::").replace(" ::", "::").replace(" ?", "?").replace("& ", "&").replace(" !", "!");
        if out.chars().count() > 90 {
            out = out.chars().take(87).collect::<String>() + "…";
        }
        out
    }

    fn line(node: &impl Spanned) -> usize {
        node.span().start().line
    }

    fn closure_like(e: &Expr) -> bool {
        match e {
            Expr::Closure(_) | Expr::Async(_) => true,
            Expr::Paren(p) => closure_like(&p.expr),
            _ => false,
        }
    }

    fn has_let(e: &Expr) -> bool {
        match e {
            Expr::Let(_) => true,
            Expr::Binary(b) if matches!(b.op, BinOp::And(_)) => has_let(&b.left) || has_let(&b.right),
            Expr::Paren(p) => has_let(&p.expr),
            _ => false,
        }
    }

    fn lock_call(m: &ExprMethodCall) -> bool {
        m.args.is_empty() && matches!(m.method.to_string().as_str(), "lock" | "try_lock" | "read" | "write")
    }

    // ---- The walk: every call in evaluation order, with the guards held then -----------------------------------------

    struct Live {
        what: String,
        /// The names a `let` bound the guard to (none for a temporary).
        bound: Vec<String>,
    }

    struct Walker<'a> {
        index: &'a Index,
        ctx: Ctx,
        /// Use what pass one learned: closures our functions run under their lock.
        synthetic: bool,
        live: Vec<Live>,
        events: Vec<Event>,
        later: Vec<(Ctx, Expr)>,
        /// The unit posts work to the main thread: a `recv` in it waits for that work.
        posts: bool,
        shortcut_vars: BTreeSet<String>,
        locals: BTreeSet<String>,
        guarded_params: BTreeSet<usize>,
    }

    /// What a walk reads: a function's body or a closure that runs on its own.
    enum Body<'b> {
        Block(&'b Block),
        Expr(&'b Expr),
    }

    impl<'a> Walker<'a> {
        fn new(index: &'a Index, ctx: Ctx, synthetic: bool, body: Body<'_>) -> Self {
            /// The names bound in a body, and the ones bound to `global_shortcut()`.
            struct Scan(BTreeSet<String>, BTreeSet<String>);
            impl<'ast> Visit<'ast> for Scan {
                fn visit_pat_ident(&mut self, i: &'ast syn::PatIdent) {
                    self.0.insert(i.ident.to_string());
                    syn::visit::visit_pat_ident(self, i);
                }
                fn visit_local(&mut self, l: &'ast syn::Local) {
                    if let Some(init) = &l.init
                        && let Expr::MethodCall(m) = &*init.expr
                        && m.method == "global_shortcut"
                    {
                        self.1.extend(pattern_names(&l.pat));
                    }
                    syn::visit::visit_local(self, l);
                }
            }
            let mut scan = Scan(ctx.params.iter().cloned().collect(), BTreeSet::new());
            let tokens = match body {
                Body::Block(b) => {
                    scan.visit_block(b);
                    b.to_token_stream()
                }
                Body::Expr(e) => {
                    scan.visit_expr(e);
                    e.to_token_stream()
                }
            };
            let posts = tokens.to_string().contains("run_on_main_thread");
            Self { index, ctx, synthetic, live: Vec::new(), events: Vec::new(), later: Vec::new(), posts, shortcut_vars: scan.1, locals: scan.0, guarded_params: BTreeSet::new() }
        }

        fn event(&mut self, line: usize, text: String, call: Call) {
            let held = self.live.iter().map(|l| format!("`{}`", l.what)).collect();
            self.events.push(Event { line, text, call, held });
        }

        fn block(&mut self, b: &Block) {
            let mark = self.live.len();
            for s in &b.stmts {
                self.stmt(s);
            }
            self.live.truncate(mark);
        }

        fn stmt(&mut self, s: &Stmt) {
            match s {
                Stmt::Local(l) => {
                    let mark = self.live.len();
                    let mut guard = None;
                    if let Some(init) = &l.init {
                        self.expr(&init.expr);
                        if self.guard_valued(&init.expr) {
                            guard = Some(text(&init.expr));
                        }
                        if let Some((_, diverge)) = &init.diverge {
                            self.expr(diverge);
                        }
                    }
                    self.live.truncate(mark);
                    if let Some(what) = guard {
                        self.live.push(Live { what, bound: pattern_names(&l.pat) });
                    }
                }
                Stmt::Expr(e, _) => self.scope(e),
                Stmt::Macro(m) => {
                    let mark = self.live.len();
                    self.mac(&m.mac);
                    self.live.truncate(mark);
                }
                Stmt::Item(_) => {}
            }
        }

        /// An expression that is a temporary scope of its own: its temporaries go at its end.
        fn scope(&mut self, e: &Expr) {
            let mark = self.live.len();
            self.expr(e);
            self.live.truncate(mark);
        }

        fn mac(&mut self, mac: &syn::Macro) {
            if let Ok(args) = mac.parse_body_with(Punctuated::<Expr, syn::Token![,]>::parse_terminated) {
                for a in &args {
                    self.expr(a);
                }
            }
        }

        /// Whether a `let` keeps the guard itself (not a value read through it).
        fn guard_valued(&self, e: &Expr) -> bool {
            match e {
                Expr::MethodCall(m) if lock_call(m) => true,
                Expr::MethodCall(m) if ["unwrap", "expect", "unwrap_or_else", "ok", "map_err"].contains(&m.method.to_string().as_str()) => self.guard_valued(&m.receiver),
                Expr::MethodCall(m) => m.args.is_empty() && self.index.resolve(&Callee::Method(m.method.to_string()), &self.ctx).iter().any(|&i| self.index.funs[i].returns_guard),
                Expr::Call(c) => match &*c.func {
                    Expr::Path(p) => self.index.resolve(&Callee::Path(segments(&p.path)), &self.ctx).iter().any(|&i| self.index.funs[i].returns_guard),
                    _ => false,
                },
                Expr::Try(t) => self.guard_valued(&t.expr),
                Expr::Paren(p) => self.guard_valued(&p.expr),
                // `match m.lock() { Ok(g) => g, Err(p) => p.into_inner() }`
                Expr::Match(m) => self.guard_valued(&m.expr) && m.arms.iter().all(|a| matches!(&*a.body, Expr::Path(_) | Expr::MethodCall(_))),
                // `let x = &guard.field;` keeps the guard for the block (temporary lifetime extension).
                Expr::Reference(r) => self.extended(&r.expr),
                _ => false,
            }
        }

        fn extended(&self, e: &Expr) -> bool {
            match e {
                Expr::Field(f) => self.extended(&f.base),
                Expr::Index(i) => self.extended(&i.expr),
                Expr::Unary(u) => self.extended(&u.expr),
                Expr::Paren(p) => self.extended(&p.expr),
                other => self.guard_valued(other),
            }
        }

        fn expr(&mut self, e: &Expr) {
            match e {
                Expr::MethodCall(m) => self.method_call(m),
                Expr::Call(c) => self.call(c),
                Expr::Closure(c) => self.scope(&c.body),
                Expr::Async(a) => self.block(&a.block),
                Expr::Block(b) => self.block(&b.block),
                Expr::Unsafe(u) => self.block(&u.block),
                Expr::Const(c) => self.block(&c.block),
                Expr::TryBlock(t) => self.block(&t.block),
                Expr::Loop(l) => self.block(&l.body),
                Expr::If(i) => self.if_(i),
                Expr::Match(m) => {
                    // The scrutinee's temporaries live through every arm; each arm is a scope of its own.
                    let mark = self.live.len();
                    self.expr(&m.expr);
                    for arm in &m.arms {
                        if let Some((_, guard)) = &arm.guard {
                            self.scope(guard);
                        }
                        self.scope(&arm.body);
                    }
                    self.live.truncate(mark);
                }
                Expr::ForLoop(f) => {
                    let mark = self.live.len();
                    self.expr(&f.expr);
                    self.block(&f.body);
                    self.live.truncate(mark);
                }
                Expr::While(w) => {
                    let mark = self.live.len();
                    if !self.condition(&w.cond) {
                        self.live.truncate(mark);
                    }
                    self.block(&w.body);
                    self.live.truncate(mark);
                }
                Expr::Let(l) => self.expr(&l.expr),
                // The assigned value is evaluated first, then the place it goes to.
                Expr::Assign(a) => {
                    self.expr(&a.right);
                    self.expr(&a.left);
                }
                Expr::Binary(b) => {
                    self.expr(&b.left);
                    if matches!(b.op, BinOp::And(_) | BinOp::Or(_)) {
                        self.scope(&b.right);
                    } else {
                        self.expr(&b.right);
                    }
                }
                Expr::Unary(u) => self.expr(&u.expr),
                Expr::Reference(r) => self.expr(&r.expr),
                Expr::RawAddr(r) => self.expr(&r.expr),
                Expr::Field(f) => self.expr(&f.base),
                Expr::Index(i) => {
                    self.expr(&i.expr);
                    self.expr(&i.index);
                }
                Expr::Paren(p) => self.expr(&p.expr),
                Expr::Group(g) => self.expr(&g.expr),
                Expr::Try(t) => {
                    self.expr(&t.expr);
                    self.result_getter(&t.expr);
                }
                Expr::Await(a) => match &*a.base {
                    // An async lock (tokio's) holds nothing the main thread could wait for.
                    Expr::MethodCall(m) if lock_call(m) => self.expr(&m.receiver),
                    base => self.expr(base),
                },
                Expr::Cast(c) => self.expr(&c.expr),
                Expr::Return(r) => {
                    if let Some(e) = &r.expr {
                        self.expr(e);
                    }
                }
                Expr::Break(b) => {
                    if let Some(e) = &b.expr {
                        self.expr(e);
                    }
                }
                Expr::Yield(y) => {
                    if let Some(e) = &y.expr {
                        self.expr(e);
                    }
                }
                Expr::Tuple(t) => t.elems.iter().for_each(|e| self.expr(e)),
                Expr::Array(a) => a.elems.iter().for_each(|e| self.expr(e)),
                Expr::Struct(s) => {
                    s.fields.iter().for_each(|f| self.expr(&f.expr));
                    if let Some(rest) = &s.rest {
                        self.expr(rest);
                    }
                }
                Expr::Range(r) => {
                    if let Some(start) = &r.start {
                        self.expr(start);
                    }
                    if let Some(end) = &r.end {
                        self.expr(end);
                    }
                }
                Expr::Repeat(r) => {
                    self.expr(&r.expr);
                    self.expr(&r.len);
                }
                Expr::Macro(m) => self.mac(&m.mac),
                _ => {}
            }
        }

        /// `if`: a plain condition's temporaries go before the block; an `if let`'s live through it (and, since Rust
        /// 2024, not through the `else`).
        fn if_(&mut self, i: &ExprIf) {
            let mark = self.live.len();
            if !self.condition(&i.cond) {
                self.live.truncate(mark);
            }
            self.block(&i.then_branch);
            self.live.truncate(mark);
            if let Some((_, other)) = &i.else_branch {
                self.expr(other);
            }
        }

        /// True when the condition binds (`let`, or a chain with one): its temporaries live through the block.
        fn condition(&mut self, c: &Expr) -> bool {
            if has_let(c) {
                self.expr(c);
                true
            } else {
                self.scope(c);
                false
            }
        }

        /// A getter of [`RESULT_GETTERS`] whose answer is used as a `Result` (`e` is that use's receiver).
        fn result_getter(&mut self, e: &Expr) {
            if let Expr::MethodCall(g) = e
                && g.args.is_empty()
                && RESULT_GETTERS.contains(&g.method.to_string().as_str())
            {
                let why = format!("`.{}()` of a window waits for the main thread's answer", g.method);
                self.event(line(&g.method), text(g), Call::Waits(why));
            }
        }

        /// The closure given to `callee` at `position` runs under one of our functions' locks.
        fn guarded_arg(&self, callee: &Callee, position: usize) -> Option<String> {
            if !self.synthetic {
                return None;
            }
            self.index
                .resolve(callee, &self.ctx)
                .into_iter()
                .find(|&i| self.index.guarded[i].contains(&position))
                .map(|i| format!("the lock {} holds while it runs this closure", self.index.funs[i].label()))
        }

        fn arg(&mut self, a: &Expr, guarded: Option<String>) {
            match guarded {
                Some(what) => {
                    let mark = self.live.len();
                    self.live.push(Live { what, bound: Vec::new() });
                    self.expr(a);
                    self.live.truncate(mark);
                }
                None => self.expr(a),
            }
        }

        fn defer(&mut self, a: &Expr, given_to: &str) {
            let mut ctx = self.ctx.clone();
            ctx.label = format!("{} (the closure given to `{given_to}`)", self.ctx.label);
            self.later.push((ctx, a.clone()));
        }

        fn shortcut_receiver(&self, r: &Expr) -> bool {
            match r {
                Expr::MethodCall(m) => m.method == "global_shortcut",
                Expr::Path(p) => p.path.get_ident().is_some_and(|i| self.shortcut_vars.contains(&i.to_string())),
                _ => false,
            }
        }

        fn primitive_method(&self, m: &ExprMethodCall) -> Option<String> {
            let name = m.method.to_string();
            let n = m.args.len();
            if TRAY_AND_MENU.contains(&name.as_str()) {
                return Some(format!("`.{name}(…)` is a tray or menu change, done on the main thread and waited for"));
            }
            if n == 0 && GETTERS.contains(&name.as_str()) {
                return Some(format!("`.{name}()` waits for the main thread's answer"));
            }
            if name == "monitor_from_point" {
                return Some("`.monitor_from_point(…)` waits for the main thread's answer".into());
            }
            if n == 0 && name == "build" && (self.ctx.builds_windows || text(&m.receiver).contains("WebviewWindowBuilder")) {
                return Some("building a window waits for the main thread".into());
            }
            if SHORTCUTS.contains(&name.as_str()) && self.shortcut_receiver(&m.receiver) {
                return Some(format!("a global shortcut's `.{name}(…)` runs on the main thread and is waited for"));
            }
            if matches!(name.as_str(), "move_window" | "move_window_constrained") {
                return Some("the positioner reads the window's monitor from the main thread".into());
            }
            if name == "exec_sync" {
                return Some("a GCD `exec_sync` waits for its queue (the main queue: the main thread)".into());
            }
            if self.posts && ((name == "recv" && n == 0) || (name == "recv_timeout" && n == 1)) {
                return Some("it waits for the task it posted to the main thread".into());
            }
            None
        }

        fn method_call(&mut self, m: &ExprMethodCall) {
            let name = m.method.to_string();
            self.expr(&m.receiver);
            if name == "run_on_main_thread" {
                // The task runs on the main thread, which never waits for itself.
                return;
            }
            let later = LATER.contains(&name.as_str());
            let callee = Callee::Method(name.clone());
            for (i, a) in m.args.iter().enumerate() {
                if later && closure_like(a) {
                    self.defer(a, &name);
                } else {
                    let guarded = self.guarded_arg(&callee, i);
                    self.arg(a, guarded);
                }
            }
            if RESULT_USE.contains(&name.as_str()) {
                self.result_getter(&m.receiver);
            }
            let at = line(&m.method);
            if let Some(why) = self.primitive_method(m) {
                self.event(at, text(m), Call::Waits(why));
            } else if NEVER.contains(&name.as_str()) && self.shortcut_receiver(&m.receiver) {
                self.event(at, text(m), Call::Never("holds the plugin's own shortcut table while it waits for the main thread, which takes that table for every key press".into()));
            } else {
                let candidates = self.index.resolve(&callee, &self.ctx);
                if !candidates.is_empty() {
                    self.event(at, text(m), Call::Ours(candidates));
                }
            }
            let returns_guard = m.args.is_empty() && self.index.resolve(&callee, &self.ctx).iter().any(|&i| self.index.funs[i].returns_guard);
            if lock_call(m) || returns_guard {
                self.live.push(Live { what: text(m), bound: Vec::new() });
            }
        }

        fn call(&mut self, c: &ExprCall) {
            let path = match &*c.func {
                Expr::Path(p) => segments(&p.path),
                other => {
                    self.expr(other);
                    Vec::new()
                }
            };
            let name = path.last().cloned().unwrap_or_default();
            // `drop(guard)`: the guard goes here.
            if (path == ["drop"] || path.ends_with(&["mem".to_string(), "drop".to_string()])) && c.args.len() == 1 {
                if let Expr::Path(p) = &c.args[0]
                    && let Some(ident) = p.path.get_ident()
                    && let Some(at) = self.live.iter().rposition(|l| l.bound.contains(&ident.to_string()))
                {
                    self.live.remove(at);
                }
                return;
            }
            // A closure the function was given (`f(x)`): what it does is the caller's (pass one notes that it runs
            // under a guard, once its arguments, which may take one, are evaluated).
            let local = path.len() == 1 && self.locals.contains(&name);
            let callee = Callee::Path(path.clone());
            let later = LATER.contains(&name.as_str());
            for (i, a) in c.args.iter().enumerate() {
                if later && closure_like(a) {
                    self.defer(a, &name);
                } else {
                    let guarded = if local { None } else { self.guarded_arg(&callee, i) };
                    self.arg(a, guarded);
                }
            }
            if local && !self.live.is_empty()
                && let Some(i) = self.ctx.params.iter().position(|p| *p == name)
            {
                self.guarded_params.insert(i);
            }
            if path.is_empty() || local {
                return;
            }
            let at = line(&c.func);
            if path.len() >= 2 && MAIN_THREAD_TYPES.contains(&path[path.len() - 2].as_str()) {
                let why = format!("`{}::{name}` builds on the main thread and waits for it", path[path.len() - 2]);
                self.event(at, text(c), Call::Waits(why));
            } else {
                let candidates = self.index.resolve(&callee, &self.ctx);
                if !candidates.is_empty() {
                    self.event(at, text(c), Call::Ours(candidates));
                }
            }
            if self.index.resolve(&callee, &self.ctx).iter().any(|&i| self.index.funs[i].returns_guard) {
                self.live.push(Live { what: text(c), bound: Vec::new() });
            }
        }
    }

    // ---- The analysis -------------------------------------------------------------------------------------------------

    struct Analysis {
        files: usize,
        findings: Vec<String>,
        /// The functions that may wait for the main thread, by label, with the road to the wait.
        waiting: BTreeMap<String, String>,
        /// Every function read, by label.
        functions: BTreeSet<String>,
    }

    fn analyse(sources: &[(String, String)]) -> Analysis {
        let mut funs = Vec::new();
        let mut aliases = BTreeMap::new();
        for (path, source) in sources {
            let file = syn::parse_file(source).unwrap_or_else(|e| panic!("{path} does not parse: {e}"));
            let mut collect = Collect { file: path, module: module_of(path), self_ty: Vec::new(), out: Vec::new(), aliases: BTreeMap::new() };
            collect.visit_file(&file);
            funs.extend(collect.out);
            aliases.insert(path.clone(), collect.aliases);
        }
        let mut index = Index::new(funs);
        let ctx_of = |f: &Fun| Ctx {
            file: f.file.clone(),
            module: f.module.clone(),
            self_ty: f.self_ty.clone(),
            params: f.params.clone(),
            builds_windows: f.builds_windows,
            label: f.label(),
            aliases: aliases.get(&f.file).cloned().unwrap_or_default(),
        };

        // Pass one: the closures each function runs while it holds a guard (`settings_with`, `update_settings`, …).
        let guarded: Vec<BTreeSet<usize>> = index
            .funs
            .iter()
            .map(|f| {
                let mut walker = Walker::new(&index, ctx_of(f), false, Body::Block(&f.body));
                walker.block(&f.body);
                walker.guarded_params
            })
            .collect();
        index.guarded = guarded;

        // Pass two: every call, with the guards held when it runs; closures that run on their own become units.
        let mut units = Vec::new();
        for (i, f) in index.funs.iter().enumerate() {
            let mut walker = Walker::new(&index, ctx_of(f), true, Body::Block(&f.body));
            walker.block(&f.body);
            let mut later = std::mem::take(&mut walker.later);
            units.push(Unit { file: f.file.clone(), label: f.label(), fun: Some(i), events: walker.events });
            while let Some((ctx, body)) = later.pop() {
                let mut walker = Walker::new(&index, ctx.clone(), true, Body::Expr(&body));
                match &body {
                    Expr::Closure(c) => walker.scope(&c.body),
                    other => walker.scope(other),
                }
                later.extend(walker.later);
                units.push(Unit { file: ctx.file.clone(), label: ctx.label.clone(), fun: None, events: walker.events });
            }
        }

        // Which functions wait, directly or through others (to a fixed point), and how.
        let mut why: Vec<Option<(String, Option<usize>)>> = vec![None; index.funs.len()];
        loop {
            let mut changed = false;
            for unit in &units {
                let Some(f) = unit.fun.filter(|&f| why[f].is_none()) else { continue };
                for e in &unit.events {
                    let found = match &e.call {
                        Call::Waits(reason) => Some((format!("`{}`: {reason}", e.text), None)),
                        Call::Ours(candidates) => candidates.iter().find(|&&c| why[c].is_some()).map(|&c| (e.text.clone(), Some(c))),
                        Call::Never(_) => None,
                    };
                    if found.is_some() {
                        why[f] = found;
                        changed = true;
                        break;
                    }
                }
            }
            if !changed {
                break;
            }
        }
        let chain = |mut f: usize| {
            let mut steps = Vec::new();
            for _ in 0..16 {
                let Some((what, next)) = &why[f] else { break };
                steps.push(format!("{} → {what}", index.funs[f].label()));
                match next {
                    Some(n) => f = *n,
                    None => break,
                }
            }
            steps.join(", ")
        };

        let mut findings = Vec::new();
        for unit in &units {
            for e in &unit.events {
                let reason = match &e.call {
                    Call::Never(reason) => Some(reason.clone()),
                    Call::Waits(reason) if !e.held.is_empty() => Some(reason.clone()),
                    Call::Ours(candidates) if !e.held.is_empty() => candidates.iter().find(|&&c| why[c].is_some()).map(|&c| format!("waits for the main thread: {}", chain(c))),
                    _ => None,
                };
                if let Some(reason) = reason {
                    let held = if e.held.is_empty() { String::new() } else { format!("holds {} across ", e.held.join(" and ")) };
                    findings.push(format!("{}:{} in {}: {held}`{}`, which {reason}", unit.file, e.line, unit.label, e.text));
                }
            }
        }
        findings.sort();
        findings.dedup();
        let waiting = index.funs.iter().enumerate().filter(|(i, _)| why[*i].is_some()).map(|(i, f)| (f.label(), chain(i))).collect();
        let functions = index.funs.iter().map(Fun::label).collect();
        Analysis { files: sources.len(), findings, waiting, functions }
    }

    /// Every source file of the crate (test modules in files of their own left out), as `src/...` paths.
    /// `MAIN_THREAD_SOURCES=<a desktop/src-tauri folder>` reads another tree instead (an older commit, to show what the
    /// rule finds there).
    fn crate_sources() -> Vec<(String, String)> {
        fn walk(dir: &Path, root: &Path, out: &mut Vec<(String, String)>) {
            let mut entries: Vec<_> = std::fs::read_dir(dir).unwrap().flatten().map(|e| e.path()).collect();
            entries.sort();
            for path in entries {
                if path.is_dir() {
                    walk(&path, root, out);
                } else if path.extension().is_some_and(|e| e == "rs") && path.file_name().is_some_and(|n| n != "tests.rs") {
                    let relative = path.strip_prefix(root).unwrap().to_string_lossy().replace('\\', "/");
                    out.push((relative, std::fs::read_to_string(&path).unwrap()));
                }
            }
        }
        let root = std::env::var_os("MAIN_THREAD_SOURCES").map_or_else(|| Path::new(env!("CARGO_MANIFEST_DIR")).to_path_buf(), std::path::PathBuf::from);
        let mut out = Vec::new();
        walk(&root.join("src"), &root, &mut out);
        out
    }

    #[test]
    fn nothing_holds_a_lock_while_it_waits_for_the_main_thread() {
        let analysis = analyse(&crate_sources());
        assert!(analysis.findings.is_empty(), "{} lock(s) held while waiting for the main thread:\n{}", analysis.findings.len(), analysis.findings.join("\n"));
    }

    #[test]
    fn the_rule_sees_every_file_and_what_waits() {
        let sources = crate_sources();
        let analysis = analyse(&sources);
        assert!(analysis.files > 100, "{} files", analysis.files);
        // `MAIN_THREAD_REPORT=1`: every function that may wait for the main thread, and how (`=all`: every function read).
        match std::env::var("MAIN_THREAD_REPORT").as_deref() {
            Ok("all") => analysis.functions.iter().for_each(|label| eprintln!("{label}")),
            Ok(_) => analysis.waiting.iter().for_each(|(label, how)| eprintln!("{label}: {how}")),
            Err(_) => {}
        }
        // App code the test-code filter once took for tests: built for a platform and for tests (`cfg(any(…, test))`).
        for label in ["screens::sampler::Sampler::with_shield", "app_life::terminate_reply", "app_life::takes_focus_at_launch"] {
            assert!(analysis.functions.contains(label), "{label} is read");
        }
        // A few that wait, each by another road: a reply posted back, the displays, a window built, the tray drawn, a
        // shortcut registered, the displays through the privacy status.
        for label in ["overlay::macos::screens", "privacy::displays", "privacy::window", "tray::draw", "shortcuts::rebind", "privacy::check::status", "lifecycle::build_main"] {
            assert!(analysis.waiting.contains_key(label), "{label} waits for the main thread: {:?}", analysis.waiting.keys());
        }
        // And some that never do.
        for label in ["tray::TrayCache::changes", "privacy::Privacy::set_hidden", "commands::status", "tray::update"] {
            assert!(!analysis.waiting.contains_key(label), "{label} must not wait for the main thread");
        }
    }

    fn findings_in(source: &str) -> Vec<String> {
        analyse(&[("src/fixture.rs".to_string(), source.to_string())]).findings
    }

    /// The function each finding is in.
    fn where_found(findings: &[String]) -> BTreeSet<String> {
        findings.iter().map(|f| f.split(" in ").nth(1).unwrap().split(": ").next().unwrap().trim_start_matches("fixture::").to_string()).collect()
    }

    #[test]
    fn the_tray_lock_of_the_v1_8_1_freeze_is_caught() {
        // tray::update as it was in the build that froze (5d9f9779): the locks held across the tray calls, which wait
        // for the main thread, while a sync command there wanted the same locks.
        let old = r#"
            pub fn update(app: &AppHandle, state: TrayState, shape: MenuShape) {
                let Some(cache) = app.try_state::<TrayCache>() else { return };
                let Some(tray) = app.tray_by_id(TRAY_ID) else { return };
                let mut last = cache.state.lock().unwrap_or_else(|p| p.into_inner());
                if *last != Some(state) {
                    *last = Some(state);
                    let _ = tray.set_tooltip(Some(tooltip(state)));
                    let _ = tray.set_icon_with_as_template(None, true);
                }
                drop(last);
                let mut current = cache.shape.lock().unwrap_or_else(|p| p.into_inner());
                if current.as_ref() != Some(&shape) {
                    if let Ok(menu) = build_menu(app, &shape) {
                        let _ = tray.set_menu(Some(menu));
                    }
                    *current = Some(shape);
                }
            }
            fn build_menu(app: &AppHandle, shape: &MenuShape) -> tauri::Result<Menu<Wry>> {
                let menu = Menu::new(app)?;
                Ok(menu)
            }
        "#;
        let findings = findings_in(old);
        let says = |held: &str, call: &str| findings.iter().any(|f| f.contains(&format!("holds `{held}")) && f.contains(&format!("across `{call}")));
        assert!(says("cache.state.lock()", "tray.set_tooltip("), "{findings:#?}");
        assert!(says("cache.state.lock()", "tray.set_icon_with_as_template("), "{findings:#?}");
        assert!(says("cache.shape.lock()", "build_menu(app, &shape)`, which waits for the main thread: fixture::build_menu → `Menu::new(app)`"), "{findings:#?}");
        assert!(says("cache.shape.lock()", "tray.set_menu("), "{findings:#?}");
        assert_eq!(findings.len(), 4, "{findings:#?}");
        // The fix: decided under the lock, drawn after letting it go.
        let fixed = r#"
            pub fn update(app: &AppHandle, state: TrayState) {
                let Some(cache) = app.try_state::<TrayCache>() else { return };
                let Some(tray) = app.tray_by_id(TRAY_ID) else { return };
                let changed = {
                    let mut last = cache.state.lock().unwrap_or_else(|p| p.into_inner());
                    let changed = *last != Some(state);
                    *last = Some(state);
                    changed
                };
                if changed {
                    let _ = tray.set_tooltip(Some(tooltip(state)));
                }
            }
        "#;
        assert_eq!(findings_in(fixed), Vec::<String>::new());
    }

    #[test]
    fn only_test_code_is_left_out() {
        // App code whose doc line ends in "test", or that is built for Windows as well as for tests, is still read.
        let source = r#"
            fn waits(app: &AppHandle) -> usize { app.available_monitors().map(|m| m.len()).unwrap_or(0) }
            /// Asked before the guided test
            fn documented(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); waits(app); }
            #[cfg(any(windows, test))]
            fn windows_and_tests(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); waits(app); }
            /// Runs the smoke test
            impl Holder { fn held(&self, app: &AppHandle) { let g = self.m.lock().unwrap(); waits(app); } }
            #[cfg(not(test))]
            fn not_in_tests(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); waits(app); }
            #[cfg(all(test, windows))]
            fn tests_on_windows(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); waits(app); }
            #[cfg(test)]
            fn test_only(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); waits(app); }
            #[test]
            fn a_test(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); waits(app); }
            #[tokio::test]
            async fn an_async_test(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); waits(app); }
            #[cfg(any(test, all(test, windows)))]
            fn test_either_way(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); waits(app); }
        "#;
        let found = where_found(&findings_in(source));
        let expected: BTreeSet<String> = ["documented", "windows_and_tests", "Holder::held", "not_in_tests"].into_iter().map(String::from).collect();
        assert_eq!(found, expected, "{:#?}", findings_in(source));
    }

    #[test]
    fn guards_live_by_rusts_scope_rules() {
        let source = r#"
            fn waits(app: &AppHandle) -> usize { app.available_monitors().map(|m| m.len()).unwrap_or(0) }
            fn binding(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); waits(app); }
            fn dropped(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); let v = *g; drop(g); waits(app); }
            fn block_ends(m: &Mutex<u32>, app: &AppHandle) { { let g = m.lock().unwrap(); } waits(app); }
            fn temporary(m: &Mutex<u32>, app: &AppHandle) { both(m.lock().unwrap().clone(), waits(app)); }
            fn statement_ends(m: &Mutex<u32>, app: &AppHandle) { let v = m.lock().unwrap().clone(); waits(app); }
            fn literal(m: &Mutex<u32>, app: &AppHandle) -> Pair { Pair { a: m.lock().unwrap().clone(), b: waits(app) } }
            fn read_first(m: &Mutex<u32>, app: &AppHandle) -> Pair { let a = m.lock().unwrap().clone(); Pair { a, b: waits(app) } }
            fn condition(m: &Mutex<Option<u32>>, app: &AppHandle) { if m.lock().unwrap().is_some() { waits(app); } }
            fn if_let(m: &Mutex<Option<u32>>, app: &AppHandle) { if let Some(v) = *m.lock().unwrap() { waits(app); } }
            fn if_let_else(m: &Mutex<Option<u32>>, app: &AppHandle) { if let Some(v) = *m.lock().unwrap() { v; } else { waits(app); } }
            fn matched(m: &Mutex<u32>, app: &AppHandle) { match m.lock() { Ok(_) => { waits(app); } Err(_) => {} } }
            fn looped(m: &Mutex<Vec<u32>>, app: &AppHandle) { for v in m.lock().unwrap().iter() { waits(app); } }
            fn assigned(m: &Mutex<usize>, app: &AppHandle) { *m.lock().unwrap() = waits(app); }
            fn posted(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); let h = app.clone(); app.run_on_main_thread(move || { waits(&h); }); }
            fn spawned(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); let h = app.clone(); std::thread::spawn(move || waits(&h)); }
            fn guard_of(m: &Mutex<u32>) -> MutexGuard<'_, u32> { m.lock().unwrap() }
            fn through_helper(m: &Mutex<u32>, app: &AppHandle) { let g = guard_of(m); waits(app); }
            struct Shared { m: Mutex<u32> }
            impl Shared { fn with(&self, f: impl FnOnce(&u32) -> usize) -> usize { f(&self.m.lock().unwrap()) } }
            fn closure_under_lock(s: &Shared, app: &AppHandle) { s.with(|v| waits(app)); }
            fn asked(app: &AppHandle) -> Option<u32> { let (tx, rx) = channel(); let _ = app.run_on_main_thread(move || { let _ = tx.send(1); }); rx.recv_timeout(Duration::from_secs(2)).ok() }
            fn asks_under_lock(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); asked(app); }
            fn monitor_scale(m: &Mutex<u32>, monitor: &Monitor) { let g = m.lock().unwrap(); monitor.scale_factor(); }
            fn window_scale(m: &Mutex<u32>, w: &WebviewWindow) { let g = m.lock().unwrap(); w.scale_factor().ok(); }
            fn shortcut_under_lock(m: &Mutex<Vec<Shortcut>>, app: &AppHandle) { let mut b = m.lock().unwrap(); for s in b.drain(..) { let _ = app.global_shortcut().unregister(s); } }
            fn never(app: &AppHandle) { let gs = app.global_shortcut(); let _ = gs.unregister_all(); }
            async fn async_lock(m: &tokio::sync::Mutex<u32>, app: &AppHandle) { let g = m.lock().await; waits(app); }
            fn match_binding(m: &Mutex<u32>, app: &AppHandle) { let g = match m.lock() { Ok(g) => g, Err(p) => p.into_inner() }; waits(app); }
            fn match_value(m: &Mutex<u32>, app: &AppHandle) { let v = match m.lock() { Ok(g) => *g, Err(_) => 0 }; waits(app); }
            mod deep { pub fn far(app: &AppHandle) -> usize { app.primary_monitor().map(|m| m.is_some() as usize).unwrap_or(0) } }
            use crate::fixture::deep as elsewhere;
            fn via_alias(m: &Mutex<u32>, app: &AppHandle) { let g = m.lock().unwrap(); elsewhere::far(app); }
            fn tray_visible(m: &Mutex<u32>, tray: &TrayIcon) { let g = m.lock().unwrap(); let _ = tray.set_visible(true); }
            fn tray_title(m: &Mutex<u32>, tray: &TrayIcon) { let g = m.lock().unwrap(); let _ = tray.set_title(Some("1")); }
            fn tray_rect(m: &Mutex<u32>, tray: &TrayIcon) { let g = m.lock().unwrap(); let _ = tray.rect(); }
            fn item_enabled(m: &Mutex<u32>, item: &MenuItem) { let g = m.lock().unwrap(); let _ = item.set_enabled(false); }
            fn main_queue(m: &Mutex<u32>) { let g = m.lock().unwrap(); DispatchQueue::main().exec_sync(|| ()); }
        "#;
        let found = where_found(&findings_in(source));
        let expected: BTreeSet<String> = ["binding", "temporary", "literal", "if_let", "matched", "looped", "through_helper", "closure_under_lock", "asks_under_lock", "window_scale", "shortcut_under_lock", "never", "match_binding", "via_alias", "tray_visible", "tray_title", "tray_rect", "item_enabled", "main_queue"].into_iter().map(String::from).collect();
        assert_eq!(found, expected, "{:#?}", findings_in(source));
    }
}
