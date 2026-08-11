import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Button, StatusChip, money, Dialog } from './ui';

describe('Button', () => {
  it('renders children and honors disabled', () => {
    render(<Button disabled>Save</Button>);
    const btn = screen.getByRole('button', { name: 'Save' });
    expect(btn).toBeDisabled();
  });

  it('shows a busy state while loading', () => {
    render(<Button loading>Save</Button>);
    const btn = screen.getByRole('button', { name: 'Save' });
    expect(btn).toHaveAttribute('aria-busy', 'true');
    expect(btn).toBeDisabled();
  });
});

describe('StatusChip', () => {
  it('always renders a text label (never color alone)', () => {
    const { rerender } = render(<StatusChip tone="success" label="Paid" />);
    expect(screen.getByText('Paid')).toBeInTheDocument();
    rerender(<StatusChip tone="danger" label="Critical" />);
    expect(screen.getByText('Critical')).toBeInTheDocument();
  });
});

describe('money', () => {
  it('formats minor units as NPR with two decimals', () => {
    expect(money(8000)).toBe('NPR 80.00');
    expect(money(5000)).toBe('NPR 50.00');
    expect(money(0)).toBe('NPR 0.00');
    expect(money(null)).toBe('NPR 0.00');
  });
});

describe('Dialog', () => {
  it('opens with the title and closes on Escape', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(
      <Dialog open onClose={onClose} title="Book appointment">
        content
      </Dialog>,
    );
    expect(screen.getByRole('dialog', { name: 'Book appointment' })).toBeInTheDocument();
    await user.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('renders nothing when closed', () => {
    render(
      <Dialog open={false} onClose={() => undefined} title="x">
        hidden
      </Dialog>,
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});
