import { useEffect, useRef } from 'react';
import { Button } from './ui';
import { Printer, Download, X, FileText, CheckCircle } from 'lucide-react';
import './print-preview.css';

interface PrintPreviewModalProps {
  open: boolean;
  html: string;
  title?: string;
  documentNumber?: string;
  status?: string;
  onClose: () => void;
  onPrint?: () => void;
  onDownload?: () => void;
  onVerify?: () => void;
  onSign?: () => void;
  showVerify?: boolean;
  showSign?: boolean;
}

/**
 * Full-screen print preview modal.
 *
 * Renders generated document HTML in a sandboxed iframe with a clean
 * toolbar for print, download, verify, and sign actions. The iframe
 * inherits the document's own styles for accurate print rendering.
 */
export function PrintPreviewModal({
  open,
  html,
  title,
  documentNumber,
  status,
  onClose,
  onPrint,
  onDownload,
  onVerify,
  onSign,
  showVerify = false,
  showSign = false,
}: PrintPreviewModalProps) {
  const iframeRef = useRef<HTMLIFrameElement>(null);

  /* ── ESC to close ── */
  useEffect(() => {
    if (!open) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [open, onClose]);

  /* ── focus iframe when opened ── */
  useEffect(() => {
    if (open && iframeRef.current) {
      iframeRef.current.focus();
    }
  }, [open]);

  /* ── print ── */
  const handlePrint = () => {
    if (onPrint) {
      onPrint();
      return;
    }
    const iframe = iframeRef.current;
    if (!iframe?.contentWindow) return;
    try {
      iframe.contentWindow.print();
    } catch {
      // Cross-origin fallback: open in new window
      const w = window.open('', '_blank');
      if (w) {
        w.document.write(html);
        w.document.close();
        w.print();
      }
    }
  };

  /* ── download ── */
  const handleDownload = () => {
    if (onDownload) {
      onDownload();
      return;
    }
    const blob = new Blob([html], { type: 'text/html' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${documentNumber || 'document'}.html`;
    a.click();
    URL.revokeObjectURL(url);
  };

  if (!open) return null;

  return (
    <div className="pp-backdrop" onClick={onClose}>
      <div className="pp-modal" onClick={(e) => e.stopPropagation()}>
        {/* Toolbar */}
        <div className="pp-toolbar">
          <div className="pp-toolbar__left">
            <FileText size={18} className="pp-toolbar__icon" />
            <div className="pp-toolbar__info">
              {title && <span className="pp-toolbar__title">{title}</span>}
              {documentNumber && <span className="pp-toolbar__number">{documentNumber}</span>}
              {status && (
                <span className={`pp-status pp-status--${status}`}>
                  {status}
                </span>
              )}
            </div>
          </div>
          <div className="pp-toolbar__right">
            {showVerify && onVerify && (
              <Button size="sm" variant="secondary" onClick={() => { onVerify(); onClose(); }}>
                <CheckCircle size={14} /> Verify
              </Button>
            )}
            {showSign && onSign && (
              <Button size="sm" variant="primary" onClick={() => { onSign(); onClose(); }}>
                <CheckCircle size={14} /> Sign
              </Button>
            )}
            <Button size="sm" variant="secondary" onClick={handlePrint}>
              <Printer size={14} /> Print
            </Button>
            <Button size="sm" variant="ghost" onClick={handleDownload}>
              <Download size={14} /> Download
            </Button>
            <button className="pp-close" onClick={onClose} aria-label="Close preview">
              <X size={18} />
            </button>
          </div>
        </div>

        {/* Document iframe */}
        <div className="pp-frame">
          {html ? (
            <iframe
              ref={iframeRef}
              srcDoc={html}
              title={title || 'Document Preview'}
              className="pp-iframe"
              sandbox="allow-same-origin allow-print"
            />
          ) : (
            <div className="pp-empty">
              <FileText size={48} />
              <p>No document content available</p>
            </div>
          )}
        </div>

        {/* Footer hint */}
        <div className="pp-footer">
          <span>Press <kbd>Esc</kbd> to close</span>
          <span>Document renders with hospital branding for accurate print output</span>
        </div>
      </div>
    </div>
  );
}

export default PrintPreviewModal;
