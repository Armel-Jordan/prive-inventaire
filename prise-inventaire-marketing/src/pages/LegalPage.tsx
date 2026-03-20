import { useLanguage } from '../i18n';
import './LegalPages.css';

export default function LegalPage() {
  const { t } = useLanguage();

  return (
    <div className="legal-page">
      <div className="container">
        <h1>{t.legal.title}</h1>
        <p className="legal-updated">{t.legal.lastUpdated}: 20 mars 2026</p>

        <section className="legal-section">
          <h2>{t.legal.sections.editor.title}</h2>
          <p><strong>{t.legal.sections.editor.companyName}:</strong> Prise Inventaire SARL</p>
          <p><strong>{t.legal.sections.editor.address}:</strong> Douala, Cameroun</p>
          <p><strong>{t.legal.sections.editor.email}:</strong> contact@prise-inventaire.com</p>
          <p><strong>{t.legal.sections.editor.phone}:</strong> +237 6XX XXX XXX</p>
          <p><strong>{t.legal.sections.editor.capital}:</strong> 1 000 000 FCFA</p>
          <p><strong>{t.legal.sections.editor.rcs}:</strong> RC/DLA/2024/XXXXX</p>
        </section>

        <section className="legal-section">
          <h2>{t.legal.sections.director.title}</h2>
          <p>{t.legal.sections.director.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.legal.sections.hosting.title}</h2>
          <p><strong>{t.legal.sections.hosting.provider}:</strong> Vercel Inc.</p>
          <p><strong>{t.legal.sections.hosting.address}:</strong> 340 S Lemon Ave #4133, Walnut, CA 91789, USA</p>
          <p><strong>{t.legal.sections.hosting.website}:</strong> https://vercel.com</p>
        </section>

        <section className="legal-section">
          <h2>{t.legal.sections.intellectual.title}</h2>
          <p>{t.legal.sections.intellectual.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.legal.sections.liability.title}</h2>
          <p>{t.legal.sections.liability.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.legal.sections.links.title}</h2>
          <p>{t.legal.sections.links.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.legal.sections.law.title}</h2>
          <p>{t.legal.sections.law.content}</p>
        </section>
      </div>
    </div>
  );
}
