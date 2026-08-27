/**
 * ContextSurface + UrgencyIndicator — Contextual Action Surfaces (Phase 116)
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { ContextSurface, UrgencyIndicator } from './ContextSurface';
import type { ContextAction } from './ContextSurface';
import type { NavModule } from '../../navigation/modules';
import { TenantProvider } from '../../context/TenantContext';
import { I18nProvider } from '../../i18n/I18nProvider';
import { AuthProvider } from '../../auth/AuthProvider';
import {
  Stethoscope,
  Users,
  FlaskConical,
} from 'lucide-react';

/* ── Helpers ── */
function renderWithProviders(ui: React.ReactNode) {
  return render(
    <MemoryRouter>
      <AuthProvider>
        <I18nProvider>
          <TenantProvider>
            {ui}
          </TenantProvider>
        </I18nProvider>
      </AuthProvider>
    </MemoryRouter>,
  );
}

function mockModule(overrides?: Partial<NavModule>): NavModule {
  return {
    key: 'clinical',
    labelKey: 'module.clinical',
    Icon: Stethoscope,
    roles: [],
    defaultTo: '/clinical',
    routePrefix: '/clinical',
    children: [
      { key: 'patients', labelKey: 'nav.patients' as any, to: '/clinical/patients', Icon: Users, roles: [] },
      { key: 'lab', labelKey: 'nav.labOrders' as any, to: '/laboratory/orders', Icon: FlaskConical, roles: [] },
    ],
    ...overrides,
  };
}

const extraActions: ContextAction[] = [
  {
    key: 'critical-lab',
    label: 'Critical Values',
    description: '3 awaiting acknowledgement',
    icon: <FlaskConical size={18} />,
    to: '/laboratory/critical-values',
    urgency: 'critical',
    count: 3,
    category: 'Alerts',
  },
  {
    key: 'waiting',
    label: 'Waiting Patients',
    description: '7 in queue',
    icon: <Users size={18} />,
    to: '/clinical/queue',
    urgency: 'attention',
    count: 7,
    category: 'Alerts',
  },
];

/* ── Tests ── */
describe('ContextSurface', () => {
  const onClose = vi.fn();

  it('renders with module children', () => {
    renderWithProviders(
      <ContextSurface module={mockModule()} open={true} onClose={onClose} />,
    );
    expect(screen.getByText('Clinical')).toBeTruthy();
    expect(screen.getByTestId('ctx-action-patients')).toBeTruthy();
  });

  it('renders extra actions with urgency', () => {
    renderWithProviders(
      <ContextSurface
        module={mockModule({ children: [] })}
        open={true}
        onClose={onClose}
        extraActions={extraActions}
      />,
    );
    expect(screen.getByText('Critical Values')).toBeTruthy();
    expect(screen.getByText('Waiting Patients')).toBeTruthy();
    expect(screen.getByTestId('ctx-action-critical-lab')).toBeTruthy();
  });

  it('renders nothing when closed', () => {
    const { container } = renderWithProviders(
      <ContextSurface module={mockModule()} open={false} onClose={onClose} />,
    );
    expect(container.querySelector('.ctx-surface')).toBeNull();
  });

  it('renders nothing when no visible actions', () => {
    const { container } = renderWithProviders(
      <ContextSurface
        module={mockModule({ children: [] })}
        open={true}
        onClose={onClose}
        extraActions={[]}
      />,
    );
    expect(container.querySelector('.ctx-surface')).toBeNull();
  });

  it('calls onClose when Escape is pressed', () => {
    renderWithProviders(
      <ContextSurface module={mockModule()} open={true} onClose={onClose} />,
    );
    fireEvent.keyDown(document.querySelector('.ctx-surface')!, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('calls onClose when close button is clicked', () => {
    renderWithProviders(
      <ContextSurface module={mockModule()} open={true} onClose={onClose} />,
    );
    fireEvent.click(screen.getByLabelText('Close Clinical'));
    expect(onClose).toHaveBeenCalled();
  });

  it('shows action count in header', () => {
    renderWithProviders(
      <ContextSurface module={mockModule()} open={true} onClose={onClose} />,
    );
    expect(screen.getByText(/action/)).toBeTruthy();
  });

  it('renders category groups from extra actions', () => {
    renderWithProviders(
      <ContextSurface
        module={mockModule({ children: [] })}
        open={true}
        onClose={onClose}
        extraActions={extraActions}
      />,
    );
    expect(screen.getByText('Alerts')).toBeTruthy();
  });
});

describe('UrgencyIndicator', () => {
  it('renders nothing for normal urgency', () => {
    const { container } = render(<UrgencyIndicator urgency="normal" />);
    expect(container.firstChild).toBeNull();
  });

  it('renders critical indicator', () => {
    render(<UrgencyIndicator urgency="critical" />);
    expect(screen.getByText('Critical')).toBeTruthy();
  });

  it('renders warning indicator', () => {
    render(<UrgencyIndicator urgency="warning" />);
    expect(screen.getByText('Warning')).toBeTruthy();
  });
});
