import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { ChefHat, Beer, Pizza, CheckCircle, Clock } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { fr } from 'date-fns/locale';

const KitchenScreen = () => {
    const [orders, setOrders] = useState([]);
    const [destination, setDestination] = useState('kitchen'); // kitchen, bar, pizza
    const [loading, setLoading] = useState(true);

    const fetchOrders = async () => {
        try {
            const resp = await axios.get(`/api/kitchen/all?destination=${destination}`);
            setOrders(resp.data);
            setLoading(false);
        } catch (err) {
            console.error('Erreur lors de la récupération des commandes', err);
        }
    };

    useEffect(() => {
        fetchOrders();
        const interval = setInterval(fetchOrders, 30000); // 30s auto-refresh
        return () => clearInterval(interval);
    }, [destination]);

    const updateStatus = async (itemId, status) => {
        try {
            await axios.put(`/api/kitchen/items/${itemId}/status`, { status });
            fetchOrders();
        } catch (err) {
            alert('Erreur lors de la mise à jour du statut.');
        }
    };

    const StatusBadge = ({ status }) => {
        const colors = {
            pending: 'bg-red-100 text-red-800 border-red-200',
            preparing: 'bg-orange-100 text-orange-800 border-orange-200',
            ready: 'bg-blue-100 text-blue-800 border-blue-200',
            done: 'bg-emerald-100 text-emerald-800 border-emerald-200',
        };
        return (
            <span className={`px-3 py-1 rounded-full text-xs font-semibold border ${colors[status] || 'bg-gray-100'}`}>
                {status.toUpperCase()}
            </span>
        );
    };

    return (
        <div className="flex flex-col h-full gap-6">
            <header className="flex items-center justify-between border-b pb-4">
                <h2 className="text-2xl font-black text-slate-800 flex items-center gap-2">
                    <ChefHat className="text-orange-500" /> ECRAN DE PRODUCTION
                </h2>
                <div className="flex bg-slate-100 p-1 rounded-xl shadow-inner gap-1">
                    {[
                        { id: 'kitchen', label: 'Cuisine', icon: <ChefHat size={18} /> },
                        { id: 'bar', label: 'Bar', icon: <Beer size={18} /> },
                        { id: 'pizza', label: 'Pizza', icon: <Pizza size={18} /> },
                    ].map((d) => (
                        <button
                            key={d.id}
                            onClick={() => setDestination(d.id)}
                            className={`flex items-center gap-2 px-6 py-2 rounded-lg transition-all font-bold ${
                                destination === d.id ? 'bg-white text-orange-600 shadow-md transform scale-105' : 'text-slate-500 hover:bg-white/50'
                            }`}
                        >
                            {d.icon}
                            {d.label}
                        </button>
                    ))}
                </div>
            </header>

            {loading ? (
                <div className="flex-1 flex items-center justify-center text-slate-400 font-medium">Chargement des commandes...</div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 overflow-y-auto">
                    {orders.length === 0 ? (
                        <div className="col-span-full py-12 text-center text-slate-400">Aucune commande en attente pour le moment.</div>
                    ) : (
                        orders.map((order) => (
                            <div key={order.id} className="bg-white border-2 border-slate-200 rounded-2xl shadow-sm hover:shadow-md transition-all overflow-hidden flex flex-col">
                                <div className="bg-slate-50 p-4 border-b flex justify-between items-center group">
                                    <div className="flex flex-col">
                                        <span className="text-sm font-bold text-slate-400"># {order.order_number}</span>
                                        <span className="text-lg font-black text-slate-800">{order.table ? `TABLE ${order.table.number}` : order.type.toUpperCase()}</span>
                                    </div>
                                    <div className="text-right">
                                        <div className="flex items-center gap-1 text-xs text-slate-400 font-bold uppercase">
                                            <Clock size={12} /> {formatDistanceToNow(new Date(order.created_at), { addSuffix: true, locale: fr })}
                                        </div>
                                    </div>
                                </div>
                                <div className="p-4 flex-1 space-y-3">
                                    {order.items.map((item) => (
                                        <div key={item.id} className="flex justify-between items-start gap-4 pb-2 border-b border-slate-100 last:border-0 last:pb-0">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-black text-lg bg-orange-100 text-orange-600 px-2 py-0.5 rounded-md min-w-[28px] text-center">{item.quantity}</span>
                                                    <span className="font-bold text-slate-800">{item.product.name}</span>
                                                </div>
                                                {item.notes && <p className="text-xs text-red-500 italic font-medium ml-9 mt-1">NB: {item.notes}</p>}
                                                {item.modifiers?.length > 0 && (
                                                  <div className="ml-9 text-xs text-slate-400">
                                                    {item.modifiers.map(m => m.modifier.name).join(', ')}
                                                  </div>
                                                )}
                                            </div>
                                            <div className="flex flex-col items-end gap-2">
                                                <StatusBadge status={item.status} />
                                                <div className="flex gap-1">
                                                    {item.status === 'pending' && (
                                                        <button 
                                                          onClick={() => updateStatus(item.id, 'preparing')}
                                                          className="p-1 px-2 bg-orange-600 text-white rounded text-xs font-bold shadow hover:bg-orange-700"
                                                        >
                                                          PREPARER
                                                        </button>
                                                    )}
                                                    {item.status === 'preparing' && (
                                                        <button 
                                                          onClick={() => updateStatus(item.id, 'ready')}
                                                          className="p-1 px-2 bg-blue-600 text-white rounded text-xs font-bold shadow hover:bg-blue-700"
                                                        >
                                                           PRET
                                                        </button>
                                                    )}
                                                    {item.status === 'ready' && (
                                                        <button 
                                                          onClick={() => updateStatus(item.id, 'done')}
                                                          className="p-1 px-2 bg-emerald-600 text-white rounded text-xs font-bold shadow hover:bg-emerald-700"
                                                        >
                                                           SERVI
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <div className="bg-slate-50 p-3 flex justify-end gap-2">
                                    <button 
                                      className="flex items-center gap-1 text-slate-500 font-bold text-xs hover:text-slate-800 transition-colors px-3 py-1 border border-slate-200 rounded-lg"
                                      onClick={() => window.print()}
                                    >
                                        RE-IMPRIMER
                                    </button>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            )}
        </div>
    );
};

export default KitchenScreen;
