@extends('layouts.app')

@section('content')
<div id="skeleton" class="max-w-5xl mx-auto px-12 py-10 space-y-4 animate-pulse">
    <div class="h-6 bg-[#1f1f25] w-1/3 rounded"></div>
    <div class="h-4 bg-[#1f1f25] w-1/2 rounded"></div>
    <div class="h-32 bg-[#1f1f25] rounded-xl"></div>
</div>

<div id="content" class="hidden max-w-5xl mx-auto px-12 py-10 fade-up">

    <h1 class="text-2xl font-semibold mb-2">{{ $product->name }}</h1>
    <p class="text-gray-400 mb-6">{{ $product->description }}</p>

    <!-- FEATURES (STATIC) -->
    <div class="feature-box p-5 mb-10">
        <h3 class="mb-3 font-semibold">Features</h3>
        <ul class="space-y-1 text-sm text-gray-300">
            @foreach ($product->features as $f)
                <li>✔ {{ $f->name }}</li>
            @endforeach
        </ul>
    </div>

    <!-- PACKAGES -->
    <h2 class="mb-4 font-semibold">Select Package</h2>

    <div class="flex gap-4 mb-10 flex-wrap">
        @foreach ($product->packages as $p)

        @php
            $badge = null;
            if(str_contains($p->name,'30')) $badge = '⭐ Best Deal';
            if(str_contains($p->name,'90')) $badge = '💎 Premium';
        @endphp

        <div onclick="selectPackage(event, {{ $p->price }}, {{ $p->id }}, '{{ $p->name }}', {{ $p->price_usdt }})"
            class="package card-glow relative p-4 w-44">

            @if($badge)
                <div class="badge">{{ $badge }}</div>
            @endif

            <p class="text-sm mb-1">
                {{ str_replace('Hari', 'Days', $p->name) }}
            </p>

            <p class="text-[#C084FC] font-semibold">
                Rp {{ number_format($p->price) }} / ${{ rtrim(rtrim($p->price_usdt,'0'),'.') }}
            </p>

        </div>
        @endforeach
    </div>

    <!-- PAYMENT -->
    <h2 class="mb-4 font-semibold">Payment Method</h2>

    <div class="flex gap-4 mb-8">

        <div onclick="selectPayment('midtrans')" id="btnMid"
            class="payment-card">
            💳 Midtrans
        </div>

        <div onclick="selectPayment('crypto')" id="btnCrypto"
            class="payment-card">
            🪙 Crypto
        </div>

    </div>

    <!-- CRYPTO -->
    <div id="cryptoBox" class="hidden mb-6 fade-up">
        <select id="cryptoSelect" class="input-glow">
            <option value="">Select Network</option>
            <option value="usdttrc20">USDT (TRC20) ⭐ Recommended</option>
            <option value="usdtbsc">USDT (BSC)</option>
            <option value="usdterc20">USDT (ERC20)</option>
            <option value="usdtmatic">USDT (Polygon)</option>
            <option value="usdtton">USDT (TON)</option>
        </select>
    </div>

    <!-- SUMMARY -->
    <div id="summaryBox" class="hidden card-glow p-6 fade-up">

        <h3 class="mb-4 font-semibold">Order Summary</h3>

        <div class="flex justify-between mb-2">
            <span>Package</span>
            <span id="selectedPackage">-</span>
        </div>

        <div class="flex justify-between mb-2">
            <span>Total</span>
            <span id="totalPrice" class="text-[#C084FC]">-</span>
        </div>

        <button id="payMainBtn" class="btn-main mt-4">
            Pay Now 🚀
        </button>
    </div>

</div>

<style>

/* FEATURE STATIC */
.feature-box{
    background:#15151B;
    border:1px solid #9333EA;
    border-radius:16px;
}

/* CARD */
.card-glow{
    background:#15151B;
    border:1px solid #27272A;
    border-radius:16px;
    transition:.3s;
}
.card-glow:hover{
    border-color:#9333EA;
    transform:translateY(-6px) scale(1.02);
    box-shadow:0 10px 25px rgba(147,51,234,0.3);
}
.package.active{
    border-color:#9333EA;
    box-shadow:0 0 20px rgba(147,51,234,0.5);
}

/* PAYMENT CARD */
.payment-card{
    flex:1;
    padding:14px;
    text-align:center;
    border:1px solid #27272A;
    border-radius:14px;
    cursor:pointer;
    transition:.3s;
}
.payment-card:hover{
    border-color:#9333EA;
    transform:scale(1.03);
    box-shadow:0 0 15px rgba(147,51,234,0.3);
}
.payment-card.active{
    border-color:#9333EA;
    box-shadow:0 0 20px rgba(147,51,234,0.5);
}

/* INPUT */
.input-glow{
    width:100%;
    background:#15151B;
    border:1px solid #27272A;
    padding:12px;
    border-radius:12px;
    color:white;
}
.input-glow:focus{
    border-color:#9333EA;
    outline:none;
    box-shadow:0 0 10px rgba(147,51,234,0.5);
}

/* BUTTON */
.btn-main{
    width:100%;
    background:#9333EA;
    padding:12px;
    border-radius:12px;
    transition:.3s;
}
.btn-main:hover{
    background:#a855f7;
    box-shadow:0 0 20px rgba(147,51,234,0.6);
}

/* BADGE */
.badge{
    position:absolute;
    top:8px;
    right:8px;
    font-size:10px;
    background:#9333EA;
    padding:3px 6px;
    border-radius:6px;
}

</style>

<script>
let selectedPackageId=null;
let selectedPayment=null;
let selectedCoin=null;
let selectedPrice=0;
let selectedUsd=0;

window.onload=()=>{
    document.getElementById('skeleton').style.display='none';
    document.getElementById('content').classList.remove('hidden');
};

function selectPackage(e,price,id,name,usd){
    selectedPackageId=id;
    selectedPrice=price;
    selectedUsd=usd;

    document.querySelectorAll('.package').forEach(el=>el.classList.remove('active'));
    e.currentTarget.classList.add('active');

    document.getElementById('selectedPackage').innerText=name.replace('Hari','Days');

    updatePrice();
    showSummary();
}

function selectPayment(type){
    selectedPayment=type;

    document.querySelectorAll('.payment-card').forEach(el=>el.classList.remove('active'));

    if(type==='crypto'){
        document.getElementById('cryptoBox').classList.remove('hidden');
        document.getElementById('btnCrypto').classList.add('active');
    }else{
        document.getElementById('cryptoBox').classList.add('hidden');
        document.getElementById('btnMid').classList.add('active');
    }

    updatePrice();
    showSummary();
}

function updatePrice(){
    let text='-';
    if(selectedPayment==='midtrans') text="Rp "+selectedPrice.toLocaleString();
    if(selectedPayment==='crypto') text="$"+selectedUsd;
    document.getElementById('totalPrice').innerText=text;
}

document.getElementById('cryptoSelect').addEventListener('change',function(){
    selectedCoin=this.value;
});

function showSummary(){
    if(selectedPackageId && selectedPayment){
        document.getElementById('summaryBox').classList.remove('hidden');
    }
}

document.getElementById('payMainBtn').onclick=function(){
    if(selectedPayment==='midtrans'){
        document.getElementById('midForm').submit();
    }
    if(selectedPayment==='crypto'){
        if(!selectedCoin){alert('Select network');return;}
        document.getElementById('cryptoForm').submit();
    }
};
</script>
@endsection