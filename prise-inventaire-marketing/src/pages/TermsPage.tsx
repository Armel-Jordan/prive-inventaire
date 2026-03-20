import { useLanguage } from '../i18n';
import './LegalPages.css';

export default function TermsPage() {
  const { t } = useLanguage();

  return (
    <div className="legal-page">
      <div className="container">
        <h1>{t.terms.title}</h1>
        <p className="legal-updated">{t.terms.lastUpdated}: 20 mars 2026</p>

        <section className="legal-section">
          <h2>{t.terms.sections.object.title}</h2>
          <p>{t.terms.sections.object.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.terms.sections.services.title}</h2>
          <p>{t.terms.sections.services.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.terms.sections.subscription.title}</h2>
          <p>{t.terms.sections.subscription.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.terms.sections.pricing.title}</h2>
          <p>{t.terms.sections.pricing.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.terms.sections.payment.title}</h2>
          <p>{t.terms.sections.payment.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.terms.sections.obligations.title}</h2>
          <p>{t.terms.sections.obligations.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.terms.sections.liability.title}</h2>
          <p>{t.terms.sections.liability.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.terms.sections.termination.title}</h2>
          <p>{t.terms.sections.termination.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.terms.sections.law.title}</h2>
          <p>{t.terms.sections.law.content}</p>
        </section>
      </div>
    </div>
  );
}
