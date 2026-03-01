import { BrowserRouter, Routes, Route } from 'react-router-dom';
import Layout from '@/components/Layout';
import Dashboard from '@/pages/Dashboard';
import ScansPage from '@/pages/ScansPage';
import ProduitsPage from '@/pages/ProduitsPage';
import SecteursPage from '@/pages/SecteursPage';
import EmployesPage from '@/pages/EmployesPage';

function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Layout />}>
          <Route index element={<Dashboard />} />
          <Route path="scans" element={<ScansPage />} />
          <Route path="produits" element={<ProduitsPage />} />
          <Route path="secteurs" element={<SecteursPage />} />
          <Route path="employes" element={<EmployesPage />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}

export default App;
