import React from 'react';

/**
 * ErrorBoundary — the last line of defence for a render-time crash.
 *
 * React unmounts the whole tree when a render throws and nothing catches it,
 * so a single bad reference in one page produced a white screen with no clue
 * as to what failed (a dangling `isOver` in the purchase request approvals
 * tab did exactly that). This keeps the failure on screen, names it, and
 * leaves the user a way out.
 *
 * It wraps the route tree rather than AdminLayout: a page renders
 * <AdminLayout>…</AdminLayout> itself, so a throw in that page happens before
 * AdminLayout mounts and a boundary inside the layout would never see it.
 * That also means the sidebar is gone when this renders — hence the explicit
 * "Back to dashboard" escape, since there is no nav left to click.
 *
 * Must stay a class: getDerivedStateFromError/componentDidCatch have no hook
 * equivalent.
 */
export default class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
    this.handleRetry = () => this.setState({ error: null });
    this.handleHome  = () => window.location.assign('/dashboard');
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    // Keep the stack in the console — the fallback deliberately shows only the
    // message, and without this the boundary would swallow the diagnosis.
    console.error('Unhandled UI error:', error, info?.componentStack);
  }

  render() {
    const { error } = this.state;
    if (!error) return this.props.children;

    return (
      <div className="ak-error-boundary" role="alert">
        <div className="ak-error-boundary__card">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" width="40" height="40" aria-hidden="true">
            <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
            <line x1="12" y1="9" x2="12" y2="13" />
            <line x1="12" y1="17" x2="12.01" y2="17" />
          </svg>
          <h1 className="ak-error-boundary__title">Something went wrong on this page.</h1>
          <p className="ak-error-boundary__body">
            The page stopped before it finished loading. Nothing you were viewing was saved or
            changed. Try again, and if it keeps happening send the message below to IT.
          </p>
          <code className="ak-error-boundary__message">{String(error?.message || error)}</code>
          <div className="ak-error-boundary__actions">
            <button type="button" className="ak-btn ak-btn--primary" onClick={this.handleRetry}>
              Try again
            </button>
            <button type="button" className="ak-btn" onClick={this.handleHome}>
              Back to dashboard
            </button>
          </div>
        </div>
      </div>
    );
  }
}
