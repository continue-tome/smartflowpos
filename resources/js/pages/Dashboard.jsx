import React from 'react';
import { Link } from 'react-router-dom';
import { LayoutDashboard, Utensils, ChefHat, Cookie, CreditCard } from 'lucide-react';

const Dashboard = () => {
  const modules = [
    { title: 'POS Vente', icon: <Utensils size={32} />, path: '/pos', color: 'bg-emerald-500' },
    { title: 'Cuisine & Bar', icon: <ChefHat size={32} />, path: '/kitchen', color: 'bg-orange-500' },
    { title: 'Ardoises Clients', icon: <CreditCard size={32} />, path: '/tabs', color: 'bg-indigo-500' },
    { title: 'Commandes Gâteaux', icon: <Cookie size={32} />, path: '/cakes', color: 'bg-pink-500' },
  ];

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 p-6">
      {modules.map((m) => (
        <Link 
          key={m.path} 
          to={m.path}
          className={`${m.color} text-white p-8 rounded-2xl shadow-lg hover:scale-105 transition-transform flex flex-col items-center justify-center gap-4 text-xl font-bold`}
        >
          {m.icon}
          {m.title}
        </Link>
      ))}
    </div>
  );
};

export default Dashboard;
