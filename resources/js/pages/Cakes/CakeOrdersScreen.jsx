import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { Cookie, Plus, Search, Calendar, CheckCircle, Printer } from 'lucide-react';
import { format } from 'date-fns';
import { fr } from 'date-fns/locale';

const CakeOrdersScreen = () => {
    const [orders, setOrders] = useState([]);
    const [loading, setLoading] = useState(true);

    const fetchOrders = async () => {
        try {
            const resp = await axios.get('/api/cake-orders');
            setOrders(resp.data);
            setLoading(false);
        } catch (err) {
            console.error('Erreur', err);
        }
    };

    useEffect(() => {
        fetchOrders();
    }, []);

    const StatusBadge = ({ status }) => {
        const colors = {
            pending: 'bg-red-100 text-red-600',
            preparing: 'bg-orange-100 text-orange-600',
            ready: 'bg-blue-100 text-blue-600',
            collected: 'bg-emerald-100 text-emerald-600',
            cancelled: 'bg-gray-100 text-gray-500',
        };
        return (
            <span className={`px-4 py-1.5 rounded-full text-[10px] font-black uppercase tracking-tighter border-2 border-white shadow-sm ${colors[status] || 'bg-gray-200 text-gray-800'}`}>
                {status}
            </span>
        );
    };

    return (
        <div className="space-y-8">
            <header className="flex flex-col md:flex-row md:items-center justify-between gap-6 border-b pb-8 border-dashed border-pink-200">
                <div className="flex items-center gap-6">
                    <div className="bg-pink-100 p-4 rounded-3xl shadow-lg shadow-pink-50 border-2 border-white">
                        <Cookie className="text-pink-500" size={40} />
                    </div>
                    <div>
                        <h2 className="text-4xl font-black text-slate-800 tracking-tighter italic">GATEAUX & PATISSERIE</h2>
                        <p className="text-slate-400 font-bold uppercase tracking-widest text-xs flex items-center gap-2">
                           <span className="w-2 h-2 rounded-full bg-pink-500 animate-pulse"></span> Gestion des commandes spéciales & anniversaires
                        </p>
                    </div>
                </div>
                <div className="flex gap-3">
                    <button 
                      className="bg-slate-800 text-white px-8 py-4 rounded-2xl font-black flex items-center gap-2 hover:bg-black shadow-xl shadow-slate-100 transition-all active:scale-95 text-sm uppercase tracking-widest"
                      onClick={() => alert('Nouvelle Commande Gâteau (Modal à implémenter)')}
                    >
                        <Plus size={20} /> CREER UNE COMMANDE
                    </button>
                </div>
            </header>

            {loading ? (
                <div className="py-24 text-center font-black text-slate-200 text-5xl uppercase italic tracking-tighter opacity-50">CHARGEMENT...</div>
            ) : (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    {orders.data?.length === 0 ? (
                        <div className="col-span-full py-24 text-center bg-white border-4 border-dashed rounded-[40px] border-pink-50 flex flex-col items-center justify-center gap-4 group hover:border-pink-200 transition-colors cursor-pointer">
                           <Cookie size={64} className="text-pink-100 group-hover:text-pink-300 transition-colors" />
                           <span className="text-pink-200 font-black text-2xl uppercase italic tracking-widest">AUCUNE COMMANDE EN COURS</span>
                        </div>
                    ) : (
                        orders.data?.map((order) => (
                           <div key={order.id} className="bg-white border-2 border-slate-50 p-8 rounded-[32px] shadow-sm hover:shadow-2xl transition-all group flex flex-col gap-6 relative overflow-hidden">
                               <div className="absolute top-0 right-0 p-8">
                                  <StatusBadge status={order.status} />
                               </div>
                               
                               <div className="flex flex-col gap-2">
                                  <h3 className="text-2xl font-black text-slate-800 uppercase italic tracking-tighter group-hover:text-pink-600 transition-colors">/ {order.customer_name}</h3>
                                  <div className="flex items-center gap-4 text-xs font-bold text-slate-400 uppercase tracking-widest">
                                      <span className="flex items-center gap-1"><Calendar size={14} /> Prévy le {format(new Date(order.delivery_date), 'dd/MM/yyyy HH:mm', { locale: fr })}</span>
                                      <span className="bg-slate-100 px-2 py-0.5 rounded text-slate-800">{order.customer_phone || 'SANS TEL'}</span>
                                  </div>
                               </div>

                               <div className="bg-slate-50 p-6 rounded-2xl border-2 border-white shadow-inner flex flex-col gap-3">
                                   <p className="text-slate-800 font-medium leading-relaxed italic border-l-4 border-pink-400 pl-4 bg-white/50 py-2 rounded-r-xl">"{order.description}"</p>
                                   <div className="flex items-center justify-between mt-2">
                                      <span className="text-xs font-black text-slate-400 uppercase tracking-widest">Prix Total</span>
                                      <span className="text-3xl font-black text-slate-800 italic tracking-tighter">{new Intl.NumberFormat().format(order.total_price)} <span className="text-sm font-normal not-italic opacity-50">FCFA</span></span>
                                   </div>
                               </div>

                               <div className="flex items-center justify-between border-t border-slate-100 pt-6">
                                   <div className="flex gap-2">
                                      <button 
                                        className="p-3 bg-slate-100 text-slate-800 rounded-xl hover:bg-slate-200 transition-colors shadow-sm"
                                        title="Imprimer ticket"
                                        onClick={() => window.open(`/api/cake-orders/${order.id}/ticket`, '_blank')}
                                      >
                                          <Printer size={20} />
                                      </button>
                                   </div>
                                   <div className="flex gap-2">
                                       {order.status === 'pending' && (
                                          <button 
                                            className="bg-orange-500 text-white px-6 py-2 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-orange-600 shadow-lg shadow-orange-100 transition-all active:scale-95"
                                            onClick={() => axios.put(`/api/cake-orders/${order.id}/status`, { status: 'preparing' }).then(fetchOrders)}
                                          >
                                              PREPARER
                                          </button>
                                       )}
                                       {order.status === 'preparing' && (
                                          <button 
                                            className="bg-blue-500 text-white px-6 py-2 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-blue-600 shadow-lg shadow-blue-100 transition-all active:scale-95"
                                            onClick={() => axios.put(`/api/cake-orders/${order.id}/status`, { status: 'ready' }).then(fetchOrders)}
                                          >
                                              PRET
                                          </button>
                                       )}
                                       {(order.status === 'ready' && order.payment_status !== 'paid') && (
                                          <button 
                                            className="bg-emerald-600 text-white px-6 py-2 rounded-xl font-black text-xs uppercase tracking-widest hover:bg-emerald-700 shadow-lg shadow-emerald-100 transition-all active:scale-95 flex items-center gap-2"
                                            onClick={() => axios.put(`/api/cake-orders/${order.id}/pay`, { payment_method: 'cash' }).then(fetchOrders)}
                                          >
                                              <CheckCircle size={14} /> ENCAISSER
                                          </button>
                                       )}
                                   </div>
                               </div>
                           </div>
                        ))
                    )}
                </div>
            )}
        </div>
    );
};

export default CakeOrdersScreen;
