// A window that hits an error while drawing shows what happened and a way back, never an empty window. Without it a
// render error unmounts everything, and on a Mac the transparent main window is left dark with only the menu bar.
import { Component, type ErrorInfo, type ReactNode } from "react";

type State = { error: Error | null };

export class WindowErrorBoundary extends Component<{ children: ReactNode; label: string }, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error(`[echoledger] the ${this.props.label} window could not draw`, error, info.componentStack);
  }

  render() {
    const { error } = this.state;
    if (!error) return this.props.children;
    // Overlay windows (captions, the pill) are small and click-through: they draw nothing rather than a message.
    if (this.props.label !== "main") return null;
    return (
      <main className="boot boot--error" role="alert">
        <div>
          <p><b>EchoLedger couldn't draw this window.</b></p>
          <p>Your recordings are safe. Reload the window to try again.</p>
          <p className="boot__detail">{error.message}</p>
          <button type="button" className="btn primary" onClick={() => location.reload()}>Reload</button>
        </div>
      </main>
    );
  }
}
