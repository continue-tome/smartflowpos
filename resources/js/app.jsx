import React from 'react';
import { BrowserRouter as Router, Routes, Route } from 'react-router-dom';
import KitchenScreen from './pages/Kitchen/KitchenScreen';
import POSScreen from './pages/POS/POSScreen';
import TabsScreen from './pages/Tabs/TabsScreen';
import CakeOrdersScreen from './pages/Cakes/CakeOrdersScreen';
import Dashboard from './pages/Dashboard';

function App() {
  return (
    <Router>
      <div className="min-h-screen bg-neutral-100 flex flex-col items-center justify-center p-4">
        <h1 className="text-4xl font-bold text-blue-600 mb-8">smartflow POS</h1>
        <div className="bg-white p-8 rounded-lg shadow-xl w-full max-w-4xl min-h-[600px]">
          <Routes>
            <Route path="/" element={<Dashboard />} />
            <Route path="/pos" element={<POSScreen />} />
            <Route path="/kitchen" element={<KitchenScreen />} />
            <Route path="/tabs" element={<TabsScreen />} />
            <Route path="/cakes" element={<CakeOrdersScreen />} />
          </Routes>
        </div>
      </div>
    </Router>
  );
}

export default App;
