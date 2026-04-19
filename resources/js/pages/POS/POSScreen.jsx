import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { ShoppingCart, Utensils, Send, Printer, User, Phone, CheckCircle, CreditCard, X, Edit3, Grid, List as ListIcon, ChefHat, Beer, Pizza } from 'lucide-react';
import { format } from 'date-fns';

const POSScreen = () => {
    const [categories, setCategories] = useState([]);
    const [products, setProducts] = useState([]);
    const [activeCategory, setActiveCategory] = useState(null);
    const [cart, setCart] = useState([]);
    const [selectedTable, setSelectedTable] = useState(null);
    const [orderType, setOrderType] = useState('dine_in'); // dine_in, takeaway, delivery, gozem
    const [customer, setCustomer] = useState({ name: '', phone: '' });
    const [loading, setLoading] = useState(true);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const isSubmittingRef = React.useRef(false);
    
    // Suivi de l'ordre pour éviter la duplication en cas de ré-essai d'impression
    const [createdOrderId, setCreatedOrderId] = useState(null);
    const [createdOrderItems, setCreatedOrderItems] = useState(null);

    // Réinitialise le suivi si l'utilisateur modifie son panier
    useEffect(() => {
        if (createdOrderId) {
            setCreatedOrderId(null);
            setCreatedOrderItems(null);
        }
    }, [cart, customer, orderType, selectedTable]);

    useEffect(() => {
        const fetchData = async () => {
            try {
                const [catsResp, prodsResp] = await [
                    await axios.get('/api/categories'),
                    await axios.get('/api/products')
                ];
                setCategories(catsResp.data);
                setProducts(prodsResp.data);
                if (catsResp.data.length > 0) setActiveCategory(catsResp.data[0].id);
                setLoading(false);
            } catch (err) {
                console.error(err);
            }
        };
        fetchData();
    }, []);

    const addToCart = (product) => {
        const existing = cart.find(i => i.product_id === product.id);
        if (existing) {
            setCart(cart.map(i => i.product_id === product.id ? { ...i, quantity: i.quantity + 1 } : i));
        } else {
            setCart([...cart, { 
                product_id: product.id, 
                name: product.name, 
                price: product.price, 
                quantity: 1, 
                notes: '',
                destination: product.category?.destination || 'kitchen'
            }]);
        }
    };

    const removeFromCart = (productId) => {
        setCart(cart.filter(i => i.product_id !== productId));
    };

    const updateQty = (productId, delta) => {
        setCart(cart.map(i => {
           if (i.product_id === productId) {
               const newQty = Math.max(1, i.quantity + delta);
               return { ...i, quantity: newQty };
           }
           return i;
        }));
    };

    const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);

    const submitOrder = async () => {
        if (cart.length === 0) return alert('Panier vide !');
        if (isSubmittingRef.current) return;
        
        isSubmittingRef.current = true;
        setIsSubmitting(true);
        try {
            // Vérifier si une session de caisse est ouverte
            const sessionResp = await axios.get('/api/cash-sessions/current');
            if (!sessionResp.data) {
                alert("Impossible d'envoyer en cuisine : Veuillez d'abord ouvrir une session de caisse !");
                isSubmittingRef.current = false;
                setIsSubmitting(false);
                return;
            }

            let orderId = createdOrderId;
            let orderItems = createdOrderItems;

            if (!orderId) {
                const resp = await axios.post('/api/orders', {
                    type: orderType,
                    table_id: selectedTable,
                    customer_name: customer.name,
                    customer_phone: customer.phone,
                    items: cart.map(i => ({
                        product_id: i.product_id,
                        quantity: i.quantity,
                        notes: i.notes
                    }))
                });
                orderId = resp.data.id;
                orderItems = resp.data.items;
                setCreatedOrderId(orderId);
                setCreatedOrderItems(orderItems);
            }

            try {
                const sendResp = await axios.post(`/api/orders/${orderId}/send-to-kitchen`, {
                    item_ids: orderItems.map(i => i.id)
                });
                alert(`Commande ${orderId} traitée ! ${sendResp.data.message || ''}`);
                
                // Seulement si ça a réussi, on vide le panier.
                setCart([]);
                setCustomer({ name: '', phone: '' });
                setCreatedOrderId(null);
                setCreatedOrderItems(null);

            } catch (printErr) {
                alert(`Commande enregistrée en système, mais l'envoi en cuisine a échoué: ` + (printErr.response?.data?.message || printErr.message) + `\n\nVous pouvez cliquer à nouveau sur ENVOYER pour réessayer l'impression de cette même commande.`);
                // Le panier N'EST PAS vidé. Le bouton reste cliquable pour relancer l'impression.
            }
        } catch (err) {
            alert('Erreur lors de la création de la commande: ' + (err.response?.data?.message || err.message));
        } finally {
            isSubmittingRef.current = false;
            setIsSubmitting(false);
        }
    };

    const filteredProducts = activeCategory 
        ? products.filter(p => p.category_id === activeCategory)
        : products;

    return (
        <div className="flex h-[calc(100vh-160px)] gap-6 antialiased text-slate-800">
            {/* Left: Products & Categories */}
            <div className="flex-1 flex flex-col gap-6">
                <nav className="flex gap-3 overflow-x-auto pb-4 scrollbar-hide">
                    {categories.map(cat => (
                        <button 
                          key={cat.id}
                          onClick={() => setActiveCategory(cat.id)}
                          className={`px-8 py-3 rounded-2xl font-black text-sm uppercase tracking-widest transition-all whitespace-nowrap shadow-sm border-2 ${
                              activeCategory === cat.id 
                              ? 'bg-slate-800 text-white border-slate-800 shadow-xl shadow-slate-200 scale-105' 
                              : 'bg-white text-slate-500 border-slate-50 hover:border-slate-200'
                          }`}
                        >
                            {cat.name}
                        </button>
                    ))}
                </nav>

                <div className="flex-1 overflow-y-auto grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 xxl:grid-cols-5 gap-4 content-start pr-2">
                    {filteredProducts.map(prod => (
                        <button 
                          key={prod.id}
                          onClick={() => addToCart(prod)}
                          className="bg-white p-5 rounded-[2.5rem] shadow-sm hover:shadow-2xl transition-all border-2 border-slate-50 group flex flex-col items-start gap-3 relative hover:-translate-y-1 active:scale-95"
                        >
                            <div className="w-12 h-12 bg-slate-50 rounded-2xl flex items-center justify-center group-hover:bg-slate-800 group-hover:text-white transition-colors">
                                {prod.category?.destination === 'bar' ? <Beer size={24} /> : prod.category?.destination === 'pizza' ? <Pizza size={24} /> : <Utensils size={24} />}
                            </div>
                            <div className="text-left w-full">
                                <h4 className="font-black text-slate-800 text-sm line-clamp-2 uppercase italic leading-tight">{prod.name}</h4>
                                <p className="text-xl font-black text-indigo-600 mt-2 italic tracking-tighter">
                                    {new Intl.NumberFormat().format(prod.price)} <span className="text-[10px] font-bold not-italic text-slate-400">FCFA</span>
                                </p>
                            </div>
                        </button>
                    ))}
                </div>
            </div>

            {/* Right: Cart & Actions */}
            <div className="w-[480px] bg-white rounded-[3rem] shadow-2xl shadow-slate-200 border-4 border-slate-50 flex flex-col overflow-hidden relative">
                <div className="p-8 bg-slate-800 text-white flex justify-between items-center">
                    <div className="flex items-center gap-3">
                        <div className="bg-white/10 p-2 rounded-xl"><ShoppingCart size={24} /></div>
                        <div>
                            <h3 className="font-black text-xl uppercase italic tracking-tighter leading-none">VOTRE PANIER</h3>
                            <span className="text-[10px] font-bold text-slate-400 tracking-widest uppercase">{cart.length} articles sélectionnés</span>
                        </div>
                    </div>
                </div>

                <div className="p-6 bg-slate-50 flex flex-col gap-4 border-b border-slate-100 shadow-inner">
                    <div className="grid grid-cols-2 gap-2">
                         {['dine_in', 'takeaway', 'delivery', 'gozem'].map(t => (
                             <button 
                                key={t}
                                onClick={() => setOrderType(t)}
                                className={`py-2 rounded-xl text-[10px] font-black uppercase tracking-widest border-2 transition-all ${
                                    orderType === t ? 'bg-indigo-600 text-white border-indigo-600 shadow-md' : 'bg-white text-slate-400 border-white hover:border-slate-100'
                                }`}
                             >
                                 {t.replace('_', ' ')}
                             </button>
                         ))}
                    </div>
                    {orderType !== 'dine_in' && (
                        <div className="flex flex-col gap-2">
                             <div className="flex gap-2">
                                <span className="flex-1 bg-white p-3 rounded-2xl flex items-center gap-2 border border-slate-100">
                                    <User size={16} className="text-slate-400" />
                                    <input 
                                      className="w-full text-xs font-bold outline-none placeholder:text-slate-300" 
                                      placeholder="Nom client..." 
                                      value={customer.name}
                                      onChange={e => setCustomer({...customer, name: e.target.value})}
                                    />
                                </span>
                                <span className="flex-1 bg-white p-3 rounded-2xl flex items-center gap-2 border border-slate-100">
                                    <Phone size={16} className="text-slate-400" />
                                    <input 
                                      className="w-full text-xs font-bold outline-none placeholder:text-slate-300" 
                                      placeholder="Téléphone..." 
                                      value={customer.phone}
                                      onChange={e => setCustomer({...customer, phone: e.target.value})}
                                    />
                                </span>
                             </div>
                        </div>
                    )}
                </div>

                <div className="flex-1 overflow-y-auto p-6 space-y-4">
                    {cart.map(item => (
                        <div key={item.product_id} className="flex items-center justify-between group animate-in fade-in slide-in-from-right-4 duration-300">
                            <div className="flex-1 min-w-0 pr-4">
                                <h5 className="font-black text-slate-800 text-xs uppercase truncate italic">{item.name}</h5>
                                <div className="flex items-center gap-2 mt-1">
                                   <span className="text-xs font-bold text-indigo-600 italic tracking-tighter">{new Intl.NumberFormat().format(item.price)} <span className="text-[9px] not-italic px-1 opacity-50">x</span></span>
                                   <div className="flex items-center bg-slate-100 rounded-lg p-1 gap-3">
                                      <button onClick={() => updateQty(item.product_id, -1)} className="w-5 h-5 flex items-center justify-center bg-white rounded shadow-sm hover:bg-slate-200">-</button>
                                      <span className="text-xs font-black w-4 text-center">{item.quantity}</span>
                                      <button onClick={() => updateQty(item.product_id, 1)} className="w-5 h-5 flex items-center justify-center bg-white rounded shadow-sm hover:bg-slate-200">+</button>
                                   </div>
                                </div>
                            </div>
                            <div className="flex flex-col items-end gap-2">
                                <span className="text-sm font-black text-slate-800 italic">{new Intl.NumberFormat().format(item.price * item.quantity)} <span className="text-[9px] opacity-40">FCFA</span></span>
                                <button onClick={() => removeFromCart(item.product_id)} className="text-slate-300 hover:text-red-500 transition-colors"><X size={14} /></button>
                            </div>
                        </div>
                    ))}
                    {cart.length === 0 && <div className="flex flex-col items-center justify-center h-full text-slate-300 gap-4 mt-20">
                         <ShoppingCart size={64} className="opacity-10 shadow-xl rounded-full" />
                         <span className="font-black uppercase italic tracking-widest text-xs opacity-50">Votre panier est vide</span>
                    </div>}
                </div>

                <div className="p-8 bg-white border-t-4 border-slate-50 flex flex-col gap-6">
                    <div className="flex justify-between items-end">
                        <span className="text-xs font-black text-slate-400 uppercase tracking-widest">Total à payer</span>
                        <span className="text-5xl font-black text-slate-800 italic tracking-tighter leading-none">{new Intl.NumberFormat().format(total)} <span className="text-lg font-normal not-italic opacity-40">FCFA</span></span>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <button className="flex flex-col items-center justify-center gap-1 bg-slate-100 text-slate-500 py-4 rounded-3xl font-black uppercase tracking-widest text-[10px] hover:bg-slate-200 transition-all shadow-sm">
                            <Printer size={20} /> IMPRIMER NOTE
                        </button>
                        <button 
                         onClick={submitOrder}
                         disabled={cart.length === 0 || isSubmitting}
                         className={`flex flex-col items-center justify-center gap-1 py-4 rounded-3xl font-black uppercase tracking-widest text-[10px] transition-all shadow-2xl shadow-orange-100 ${
                             (cart.length > 0 && !isSubmitting) ? 'bg-orange-600 text-white hover:bg-orange-700 active:scale-95' : 'bg-slate-50 text-slate-300 grayscale pointer-events-none'
                         }`}
                        >
                            <Send size={20} /> {isSubmitting ? 'ENVOI EN COURS...' : 'ENVOYER EN CUISINE'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default POSScreen;
