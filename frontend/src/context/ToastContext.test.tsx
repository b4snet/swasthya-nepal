import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { ToastProvider, useToast } from './ToastContext';

function TestConsumer() {
  const { toast, success, error } = useToast();
  return (
    <div>
      <button data-testid="show-info" onClick={() => toast('info', 'Info message')}>info</button>
      <button data-testid="show-success" onClick={() => success('Saved!')}>success</button>
      <button data-testid="show-error" onClick={() => error('Failed!')}>error</button>
      <button data-testid="show-permanent" onClick={() => toast('warning', 'Manual only', 0)}>permanent</button>
    </div>
  );
}

function renderToast() {
  return render(
    <ToastProvider>
      <TestConsumer />
    </ToastProvider>,
  );
}

describe('ToastContext', () => {
  it('renders a toast with the given message and tone', async () => {
    const user = userEvent.setup();
    renderToast();
    await user.click(screen.getByTestId('show-success'));
    expect(screen.getByText('Saved!')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveClass('toast--success');
  });

  it('renders error toast with danger tone', async () => {
    const user = userEvent.setup();
    renderToast();
    await user.click(screen.getByTestId('show-error'));
    expect(screen.getByText('Failed!')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveClass('toast--error');
  });

  it('dismisses a toast when the close button is clicked', async () => {
    const user = userEvent.setup();
    renderToast();
    await user.click(screen.getByTestId('show-info'));
    expect(screen.getByText('Info message')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Dismiss' }));
    expect(screen.queryByText('Info message')).not.toBeInTheDocument();
  });

  it('renders multiple toasts simultaneously', async () => {
    const user = userEvent.setup();
    renderToast();
    await user.click(screen.getByTestId('show-success'));
    await user.click(screen.getByTestId('show-error'));
    expect(screen.getByText('Saved!')).toBeInTheDocument();
    expect(screen.getByText('Failed!')).toBeInTheDocument();
    expect(screen.getAllByRole('status')).toHaveLength(2);
  });

  it('does not render a toast container when no toasts exist', () => {
    renderToast();
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
  });

  it('renders info and warning toasts with correct tones', async () => {
    const user = userEvent.setup();
    renderToast();
    await user.click(screen.getByTestId('show-info'));
    expect(screen.getByRole('status')).toHaveClass('toast--info');
  });
});
