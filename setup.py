from setuptools import setup, find_packages

setup(
    name="pandugizi-convergent-ai",
    version="1.0.0",
    description="An Explainable and Generative Convergent AI Architecture for Nutritional Follow-Up in Primary Healthcare",
    author="Raisya Putri Agustin, Eka Miranda",
    author_email="raisya.agustin@binus.ac.id, ekamiranda@binus.ac.id",
    packages=find_packages(),
    python_requires=">=3.8",
    install_requires=[],
    extras_require={
        "dev": [
            "pytest>=7.0.0",
            "python-dotenv>=1.0.0",
            "requests>=2.28.0",
        ]
    },
    classifiers=[
        "Development Status :: 4 - Beta",
        "Intended Audience :: Science/Research",
        "Topic :: Scientific/Engineering :: Artificial Intelligence",
        "Topic :: Scientific/Engineering :: Medical Science Apps.",
        "License :: OSI Approved :: MIT License",
        "Programming Language :: Python :: 3",
    ],
)
