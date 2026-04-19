import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { CreditCard, Plus, Search, FileText, Trash2, DollarSign } from 'lucide-react';
import { format } from 'date-fns';
import { fr } from 'date-fns/locale';

const TabsScreen = () => {
    const [tabs, setTabs] = useState([]);
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(true);

    const fetchTabs = async () => {
        try {
            const resp = await axios.get(`/api/customer-tabs?search=${search}`);
            setTabs(resp.data);
            setLoading(false);
        } catch (err) {
            console.error('Erreur', err);
        }
    };

    useEffect(() => {
        fetchTabs();
    }, [search]);

    const createTab = async () => {
        const name = prompt('Nom du client :');
        if (!name) return;
        const phone = prompt('Téléphone (optionnel) :');
        try {
            await axios.post('/api/customer-tabs', { customer_name: name, customer_phone: phone });
            fetchTabs();
        } catch (err) {
            alert('Erreur lors de la création.');
        }
    };

    return (
        <div className="space-y-6">
            <header className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b pb-4">
                <div>
                    <h2 className="text-3xl font-black text-slate-800 flex items-center gap-3 italic">
                        <CreditCard className="text-indigo-600" size={32} /> GESTION DES ARDOISES
                    </h2>
                    <p className="text-slate-400 font-bold uppercase tracking-widest text-[10px]">Suivi des dettes et comptes clients</p>
                </div>
                <div className="flex gap-2">
                   <div className="relative">
                       <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                       <input 
                         type="text" 
                         placeholder="Rechercher un client..."
                         value={search}
                         onChange={(e) => setSearch(e.target.value)}
                         className="pl-10 pr-4 py-3 bg-slate-100 border-0 rounded-xl focus:ring-2 focus:ring-indigo-500 w-full sm:w-64 font-medium"
                       />
                   </div>
                   <button 
                     onClick={createTab}
                     className="bg-indigo-600 text-white px-6 py-3 rounded-xl font-black flex items-center gap-2 hover:bg-indigo-700 shadow-lg shadow-indigo-200 transition-all active:scale-95"
                   >
                       <Plus size={20} /> NOUVEAU
                   </button>
                </div>
            </header>

            {loading ? (
                <div className="py-20 text-center font-bold text-slate-300 italic tracking-widest">CHARGEMENT DES ARDOISES...</div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {tabs.data?.length === 0 ? (
                        <div className="col-span-full py-20 text-center text-slate-300 font-bold uppercase italic tracking-widest border-2 border-dashed rounded-3xl border-slate-200 bg-slate-50">AUCUNE ARDOISE TROUVEE</div>
                    ) : (
                        tabs.data?.map((tab) => (
                            <TabCard key={tab.id} tab={tab} onRefresh={fetchTabs} />
                        ))
                    )}
                </div>
            )}
        </div>
    );
};

const TabCard = ({ tab, onRefresh }) => {
    const handleClose = async () => {
        if (!confirm('Clôturer cette ardoise ?')) return;
        try {
            await axios.put(`/api/customer-tabs/${tab.id}/close`, { payment_method: 'cash' });
            onRefresh();
        } catch (err) {
            alert('Impossible de clôturer l\'ardoise (probablement déjà payée).');
        }
    };

    return (
        <div className="bg-white border-2 border-slate-100 rounded-3xl shadow-sm hover:shadow-xl transition-all p-6 flex flex-col gap-4 group">
            <div className="flex items-center justify-between">
                <div>
                   <h3 className="text-xl font-black text-slate-800 uppercase italic">/ {tab.customer_name}</h3>
                   <p className="text-xs text-slate-400 font-bold">{tab.customer_phone || 'SANS CONTACT'}</p>
                </div>
                <div className={`px-4 py-1 rounded-full text-[10px] font-black uppercase tracking-tighter ${tab.status === 'open' ? 'bg-orange-100 text-orange-600' : 'bg-emerald-100 text-emerald-600'}`}>
                    {tab.status}
                </div>
            </div>

            <div className="bg-slate-50 rounded-2xl p-4 flex items-center justify-between shadow-inner">
                <span className="text-xs font-black text-slate-400 uppercase tracking-widest">SOLDE ACTUEL</span>
                <span className="text-2xl font-black text-indigo-700 tracking-tighter">{new Intl.NumberFormat().format(tab.total_amount)} <span className="text-sm font-normal">FCFA</span></span>
            </div>

            <div className="flex flex-col gap-2">
                <div className="flex items-center justify-between text-xs font-bold text-slate-400 uppercase border-b pb-2">
                    <span>Infos Session</span>
                    <span>{format(new Date(tab.created_at), 'dd MMM yyyy', { locale: fr })}</span>
                </div>
                <div className="flex justify-between items-center text-sm">
                   <span className="text-slate-400 font-medium">Commandes liées :</span>
                   <span className="font-black text-slate-800">{tab.orders_count || 0}</span>
                </div>
            </div>

            <div className="flex grid grid-cols-2 gap-2 mt-4 opacity-0 group-hover:opacity-100 transition-opacity">
                <button 
                  className="flex items-center justify-center gap-2 bg-slate-800 text-white rounded-xl py-3 text-xs font-black hover:bg-black"
                  onClick={() => window.open(`/api/customer-tabs/${tab.id}/invoice`, '_blank')}
                >
                    <FileText size={16} /> FACTURE
                </button>
                {tab.status === 'open' && (
                   <button 
                     className="flex items-center justify-center gap-2 bg-emerald-600 text-white rounded-xl py-3 text-xs font-black hover:bg-emerald-700 shadow-md shadow-emerald-100 text-sm"
                     onClick={handleClose}
                   >
                       <DollarSign size={16} /> PAYER
                   </button>
                )}
            </div>
        </div>
    );
};

export default TabsScreen;
