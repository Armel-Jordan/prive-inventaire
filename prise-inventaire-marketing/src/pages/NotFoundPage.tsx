import { Link } from 'react-router-dom';
import { Home, ArrowLeft } from 'lucide-react';
import { useLanguage } from '../i18n';
import SEO from '../components/SEO';
import './NotFoundPage.css';

export default function NotFoundPage() {
  const { t } = useLanguage();

  return (
    <>
      <SEO 
        title={t.notFound.title}
        description={t.notFound.description}
      />
      <div className="not-found-page">
        <div className="container">
          <div className="not-found-content">
            <h1 className="not-found-code">404</h1>
            <h2>{t.notFound.title}</h2>
            <p>{t.notFound.description}</p>
            <div className="not-found-actions">
              <Link to="/" className="btn btn-primary">
                <Home size={20} />
                {t.notFound.homeButton}
              </Link>
              <button onClick={() => window.history.back()} className="btn btn-secondary">
                <ArrowLeft size={20} />
                {t.notFound.backButton}
              </button>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
