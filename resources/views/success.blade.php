@extends('layouts.app')

@section('content')

<div class="max-w-xl mx-auto py-20 text-center">

    <h1 class="text-3xl font-bold mb-4 text-green-400 animate-pulse">
        Payment Successful 🎉
    </h1>

    <p class="text-gray-400 mb-8">
        License kamu siap digunakan 🚀
    </p>

    <div class="bg-[#15151B] border border-[#27272A] rounded-xl p-6 mb-6 shadow-xl">

        <p class="text-sm text-gray-400 mb-2">License Key</p>

        <div id="licenseKey" class="font-mono text-lg text-[#C084FC] mb-4 break-all">
            {{ $license->license_key ?? 'Loading...' }}
        </div>

        <button onclick="copyKey()" 
        class="bg-[#9333EA] px-5 py-2 rounded-lg hover:bg-[#7E22CE] transition">
            Copy License
        </button>

    </div>

    <a href="/licenses" 
    class="inline-block mt-4 text-sm text-gray-400 hover:text-white">
        Lihat semua license →
    </a>

</div>

<!-- CONFETTI -->
<canvas id="confetti"></canvas>

<script>
function copyKey() {
    const text = document.getElementById('licenseKey').innerText;
    navigator.clipboard.writeText(text);
    alert("Copied! 🔥");
}

// AUTO COPY
window.onload = () => {
    const text = document.getElementById('licenseKey').innerText;
    if(text !== "Loading..."){
        navigator.clipboard.writeText(text);
    }
};

// SIMPLE CONFETTI
const canvas = document.getElementById("confetti");
const ctx = canvas.getContext("2d");

canvas.width = window.innerWidth;
canvas.height = window.innerHeight;

let pieces = [];

for (let i = 0; i < 80; i++) {
    pieces.push({
        x: Math.random() * canvas.width,
        y: Math.random() * canvas.height,
        r: Math.random() * 6 + 4,
        d: Math.random() * 80,
        color: "hsl(" + Math.random() * 360 + ", 70%, 60%)",
        tilt: Math.floor(Math.random() * 10) - 10
    });
}

function draw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    pieces.forEach(p => {
        ctx.beginPath();
        ctx.fillStyle = p.color;
        ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2, false);
        ctx.fill();
    });

    update();
}

function update() {
    pieces.forEach(p => {
        p.y += Math.cos(p.d) + 1;
        p.x += Math.sin(p.d);

        if (p.y > canvas.height) {
            p.y = 0;
        }
    });
}

setInterval(draw, 20);
</script>

@endsection