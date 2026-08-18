import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { I18nProvider } from '../i18n/I18nProvider';
import { ForbiddenPage } from './ForbiddenPage';

function renderForbidden() {
  return render(
    <MemoryRouter>
      <I18nProvider>
        <ForbiddenPage />
      </I18nProvider>
    </MemoryRouter>,
  );
}

describe('ForbiddenPage', () => {
  it('renders the access denied heading', () => {
    renderForbidden();
    expect(screen.getByRole('heading', { name: /access denied/i })).toBeInTheDocument();
  });

  it('renders a permission explanation', () => {
    renderForbidden();
    expect(screen.getByText(/do not have permission/i)).toBeInTheDocument();
  });

  it('provides a link back to the dashboard', () => {
    renderForbidden();
    const link = screen.getByRole('link', { name: /back to dashboard/i });
    expect(link).toHaveAttribute('href', '/');
  });
});
