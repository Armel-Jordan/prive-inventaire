import { useLanguage } from '../i18n';
import './LegalPages.css';

export default function PrivacyPage() {
  const { t } = useLanguage();

  return (
    <div className="legal-page">
      <div className="container">
        <h1>{t.privacy.title}</h1>
        <p className="legal-updated">{t.privacy.lastUpdated}: 20 mars 2026</p>

        <section className="legal-section">
          <h2>{t.privacy.sections.intro.title}</h2>
          <p>{t.privacy.sections.intro.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.privacy.sections.dataCollected.title}</h2>
          <p>{t.privacy.sections.dataCollected.intro}</p>
          <ul>
            {t.privacy.sections.dataCollected.items.map((item, i) => (
              <li key={i}>{item}</li>
            ))}
          </ul>
        </section>

        <section className="legal-section">
          <h2>{t.privacy.sections.usage.title}</h2>
          <p>{t.privacy.sections.usage.intro}</p>
          <ul>
            {t.privacy.sections.usage.items.map((item, i) => (
              <li key={i}>{item}</li>
            ))}
          </ul>
        </section>

        <section className="legal-section">
          <h2>{t.privacy.sections.storage.title}</h2>
          <p>{t.privacy.sections.storage.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.privacy.sections.sharing.title}</h2>
          <p>{t.privacy.sections.sharing.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.privacy.sections.rights.title}</h2>
          <p>{t.privacy.sections.rights.intro}</p>
          <ul>
            {t.privacy.sections.rights.items.map((item, i) => (
              <li key={i}>{item}</li>
            ))}
          </ul>
          <p>{t.privacy.sections.rights.contact}</p>
        </section>

        <section className="legal-section">
          <h2>{t.privacy.sections.cookies.title}</h2>
          <p>{t.privacy.sections.cookies.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.privacy.sections.changes.title}</h2>
          <p>{t.privacy.sections.changes.content}</p>
        </section>

        <section className="legal-section">
          <h2>{t.privacy.sections.contact.title}</h2>
          <p>{t.privacy.sections.contact.content}</p>
        </section>
      </div>
    </div>
  );
}
