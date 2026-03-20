import { Link } from 'react-router-dom';
import { 
  Check, 
  X, 
  ArrowRight,
  Smartphone,
  Monitor,
  Users,
  HelpCircle,
  Zap
} from 'lucide-react';
import { useLanguage } from '../i18n';
import './TarifsPage.css';

export default function TarifsPage() {
  const { t } = useLanguage();

  const plans = [
    {
      name: t.pricing.plans.starter.name,
      description: t.pricing.plans.starter.description,
      price: '25 000',
      currency: 'FCFA',
      period: '/mois',
      features: [
        { text: t.pricing.features.mobileUsers.replace('{count}', '2'), included: true },
        { text: t.pricing.features.webAdmins.replace('{count}', '1'), included: true },
        { text: t.pricing.features.products.replace('{count}', '500'), included: true },
        { text: t.pricing.features.sectors.replace('{count}', '1'), included: true },
        { text: t.pricing.features.supportEmail, included: true },
        { text: t.pricing.features.offline, included: true },
        { text: t.pricing.features.reportsBasic, included: true },
        { text: t.pricing.features.multisite, included: false },
        { text: t.pricing.features.api, included: false },
        { text: t.pricing.features.trainingNone, included: false },
      ],
      popular: false,
      cta: t.pricing.plans.starter.cta
    },
    {
      name: t.pricing.plans.business.name,
      description: t.pricing.plans.business.description,
      price: '75 000',
      currency: 'FCFA',
      period: '/mois',
      features: [
        { text: t.pricing.features.mobileUsers.replace('{count}', '10'), included: true },
        { text: t.pricing.features.webAdmins.replace('{count}', '5'), included: true },
        { text: t.pricing.features.productsUnlimited, included: true },
        { text: t.pricing.features.sectorsMultiple.replace('{count}', '5'), included: true },
        { text: t.pricing.features.supportPriority, included: true },
        { text: t.pricing.features.offline, included: true },
        { text: t.pricing.features.reportsAdvanced, included: true },
        { text: t.pricing.features.multisite, included: true },
        { text: t.pricing.features.api, included: false },
        { text: t.pricing.features.trainingOne, included: true },
      ],
      popular: true,
      cta: t.pricing.plans.business.cta
    },
    {
      name: t.pricing.plans.enterprise.name,
      description: t.pricing.plans.enterprise.description,
      price: t.pricing.plans.enterprise.price,
      currency: '',
      period: '',
      features: [
        { text: t.pricing.features.mobileUnlimited, included: true },
        { text: t.pricing.features.webUnlimited, included: true },
        { text: t.pricing.features.productsUnlimited, included: true },
        { text: t.pricing.features.sectorsUnlimited, included: true },
        { text: t.pricing.features.supportDedicated, included: true },
        { text: t.pricing.features.offline, included: true },
        { text: t.pricing.features.reportsCustom, included: true },
        { text: t.pricing.features.multisite, included: true },
        { text: t.pricing.features.api, included: true },
        { text: t.pricing.features.trainingUnlimited, included: true },
      ],
      popular: false,
      cta: t.pricing.plans.enterprise.cta
    }
  ];

  const faqs = [
    { question: t.pricing.faq.q1.question, answer: t.pricing.faq.q1.answer },
    { question: t.pricing.faq.q2.question, answer: t.pricing.faq.q2.answer },
    { question: t.pricing.faq.q3.question, answer: t.pricing.faq.q3.answer },
    { question: t.pricing.faq.q4.question, answer: t.pricing.faq.q4.answer },
    { question: t.pricing.faq.q5.question, answer: t.pricing.faq.q5.answer },
    { question: t.pricing.faq.q6.question, answer: t.pricing.faq.q6.answer },
  ];

  return (
    <div className="tarifs-page">
      {/* Hero */}
      <section className="tarifs-hero">
        <div className="container">
          <h1>{t.pricing.title}</h1>
          <p>{t.pricing.subtitle}</p>
        </div>
      </section>

      {/* Plans */}
      <section className="section plans-section">
        <div className="container">
          <div className="plans-grid">
            {plans.map((plan, index) => (
              <div key={index} className={`plan-card ${plan.popular ? 'popular' : ''}`}>
                {plan.popular && <span className="popular-badge">{t.pricing.popular}</span>}
                <div className="plan-header">
                  <h3>{plan.name}</h3>
                  <p>{plan.description}</p>
                </div>
                <div className="plan-price">
                  <span className="price">{plan.price}</span>
                  {plan.currency && <span className="currency">{plan.currency}</span>}
                  {plan.period && <span className="period">{plan.period}</span>}
                </div>
                <ul className="plan-features">
                  {plan.features.map((feature, i) => (
                    <li key={i} className={feature.included ? 'included' : 'excluded'}>
                      {feature.included ? <Check size={18} /> : <X size={18} />}
                      {feature.text}
                    </li>
                  ))}
                </ul>
                <Link 
                  to="/contact" 
                  className={`btn ${plan.popular ? 'btn-primary' : 'btn-secondary'} plan-cta`}
                >
                  {plan.cta} <ArrowRight size={18} />
                </Link>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* What's included */}
      <section className="section included-section">
        <div className="container">
          <h2 className="section-title">{t.pricing.included.title}</h2>
          <div className="included-grid">
            <div className="included-item">
              <Smartphone size={32} />
              <h4>{t.pricing.included.mobile.title}</h4>
              <p>{t.pricing.included.mobile.description}</p>
            </div>
            <div className="included-item">
              <Monitor size={32} />
              <h4>{t.pricing.included.web.title}</h4>
              <p>{t.pricing.included.web.description}</p>
            </div>
            <div className="included-item">
              <Zap size={32} />
              <h4>{t.pricing.included.updates.title}</h4>
              <p>{t.pricing.included.updates.description}</p>
            </div>
            <div className="included-item">
              <Users size={32} />
              <h4>{t.pricing.included.support.title}</h4>
              <p>{t.pricing.included.support.description}</p>
            </div>
          </div>
        </div>
      </section>

      {/* FAQ */}
      <section className="section faq-section">
        <div className="container">
          <h2 className="section-title">{t.pricing.faq.title}</h2>
          <div className="faq-grid">
            {faqs.map((faq, index) => (
              <div key={index} className="faq-item">
                <h4>
                  <HelpCircle size={20} />
                  {faq.question}
                </h4>
                <p>{faq.answer}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* CTA */}
      <section className="tarifs-cta">
        <div className="container">
          <h2>{t.pricing.cta.title}</h2>
          <p>{t.pricing.cta.subtitle}</p>
          <Link to="/contact" className="btn btn-accent">
            {t.pricing.cta.button} <ArrowRight size={18} />
          </Link>
        </div>
      </section>
    </div>
  );
}
