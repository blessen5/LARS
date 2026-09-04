from flask import Flask, render_template, request

app = Flask(__name__)

def get_recommendation(budget, usage):
    """Generate PC configuration based on budget and usage"""
    budget = int(budget)
    
    # Base components (same for all configurations)
    config = {
        'case': 'Mid-tower ATX Case',
        'power_supply': '550W 80+ Bronze PSU',
        'storage': '1TB NVMe SSD',
        'ram': '16GB DDR4 3200MHz',
        'motherboard': 'B550 Motherboard',
        'cooler': 'Stock CPU Cooler'
    }
    
    # Adjust configuration based on budget and usage
    if usage == 'programming':
        if budget >= 1500:
            config.update({
                'cpu': 'AMD Ryzen 9 5900X',
                'gpu': 'NVIDIA RTX 3070',
                'ram': '32GB DDR4 3600MHz',
                'storage': '1TB NVMe SSD + 2TB HDD',
                'power_supply': '750W 80+ Gold PSU'
            })
        elif budget >= 1000:
            config.update({
                'cpu': 'AMD Ryzen 7 5800X',
                'gpu': 'NVIDIA RTX 3060',
                'ram': '16GB DDR4 3200MHz',
                'storage': '1TB NVMe SSD',
                'power_supply': '650W 80+ Bronze PSU'
            })
        else:
            config.update({
                'cpu': 'AMD Ryzen 5 5600X',
                'gpu': 'NVIDIA GTX 1660 Super',
                'ram': '16GB DDR4 3200MHz',
                'storage': '500GB NVMe SSD',
                'power_supply': '550W 80+ Bronze PSU'
            })
    else:  # office usage
        if budget >= 1000:
            config.update({
                'cpu': 'AMD Ryzen 5 5600G',
                'gpu': 'Integrated Graphics',
                'ram': '16GB DDR4 3200MHz',
                'storage': '1TB NVMe SSD',
                'power_supply': '450W 80+ Bronze PSU'
            })
        else:
            config.update({
                'cpu': 'AMD Ryzen 3 5300G',
                'gpu': 'Integrated Graphics',
                'ram': '8GB DDR4 3200MHz',
                'storage': '500GB NVMe SSD',
                'power_supply': '400W 80+ Bronze PSU'
            })
    
    # Calculate total price (simplified estimation)
    config['estimated_price'] = f"${budget}"
    return config

@app.route('/', methods=['GET', 'POST'])
def index():
    if request.method == 'POST':
        budget = request.form.get('budget', 1000)
        usage = request.form.get('usage', 'office')
        
        if not budget.isdigit() or int(budget) < 400:
            error = "Please enter a valid budget (minimum $400)."
            return render_template('index.html', error=error)
            
        recommendation = get_recommendation(budget, usage)
        return render_template('result.html', recommendation=recommendation, budget=budget, usage=usage)
    
    return render_template('index.html')

if __name__ == '__main__':
    app.run(debug=True)
